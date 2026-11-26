<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\AttendanceRecord;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Notifications\NotificationService;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A student's or staff member's own leave/permission request — see
 * LeaveRequest's own docblock for why this is a separate entity from
 * AttendanceRecord. approve() is the one place that ever writes attendance
 * on a student's behalf here (never for a staff-owned request), and it does
 * so entirely through the existing, unmodified
 * AttendanceService::recordForClass() — one call per (class, date) actually
 * affected, not a hand-rolled upsert.
 */
final class LeaveRequestService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AttendanceService $attendance,
        private readonly NotificationService $notifications,
        private readonly ApprovalFlow $flow,
        private readonly LeaveBalanceService $balances,
    ) {}

    /**
     * `$student` xor `$staff` — whichever the signed-in account is linked
     * to; see MyLeaveRequestController::requesterOrFail().
     *
     * A staff member's request is checked against its leave type and policy
     * (see LeaveBalanceService::check()) with their staff row locked, so two
     * requests sent at once can't both spend the same balance. `$byHr`: HR
     * filing it on their behalf (HRM > Leave Management > Leave request).
     *
     * @param  array{leave_type_id?:int|null, day_part?:string|null, from_date:string, to_date:string, from_time?:string|null, to_time?:string|null, reason:string, attachments?:list<UploadedFile>}  $data
     */
    public function submit(?Student $student, ?Staff $staff, array $data, bool $byHr = false): LeaveRequest
    {
        // A student needs no permission for a day off: refuse a request that
        // falls wholly on holidays; one only partly on them goes through and
        // reports the days it skips (approval leaves those days alone).
        $holidayDates = null;
        if ($student !== null) {
            // First, before anything walks the dates: a mistyped year (2056
            // for 2026) once made a 30-year request whose approval timed out.
            $this->guardMaxDays($data['from_date'], $data['to_date']);
            $holidayDates = $this->holidayDates($data['from_date'], $data['to_date']);
            if (count($holidayDates) === count($this->datesBetween($data['from_date'], $data['to_date']))) {
                throw ValidationException::withMessages([
                    'from_date' => __('This day is a holiday (:names) — no permission is needed.', [
                        'names' => $this->holidayNames($data['from_date'], $data['to_date']),
                    ]),
                ]);
            }

            $this->guardNotStarted($student, $data);
            $this->guardAbsenceLimit($student, $data['enrollment_id'] ?? null, $data['from_date'], $data['to_date']);
        }

        $request = DB::connection('tenant')->transaction(function () use ($student, $staff, $data, $byHr) {
            $leave = ['leave_type_id' => null, 'day_part' => null, 'days' => null];
            if ($staff !== null) {
                $staff = Staff::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
                $leave = $this->balances->check($staff, [...$data, 'has_attachment' => ($data['attachments'] ?? []) !== []], $byHr);
            }

            $request = LeaveRequest::query()->create([
                'student_id' => $student?->id,
                'staff_id' => $staff?->id,
                'enrollment_id' => $student !== null ? ($data['enrollment_id'] ?? null) : null,
                ...$leave,
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                // A half day is the day part, not clock times.
                'from_time' => $leave['day_part'] !== null && $leave['day_part'] !== LeaveRequest::DAY_FULL ? null : ($data['from_time'] ?? null),
                'to_time' => $leave['day_part'] !== null && $leave['day_part'] !== LeaveRequest::DAY_FULL ? null : ($data['to_time'] ?? null),
                'reason' => $data['reason'],
                'status' => LeaveRequest::STATUS_PENDING,
            ]);

            $tenant = $this->context->getOrFail();

            foreach ($data['attachments'] ?? [] as $file) {
                $path = $file->store($tenant->storagePath('leave-request-attachments'), 'public');

                if ($path === false) {
                    abort(500, 'Failed to store an attached file.');
                }

                $request->attachments()->create([
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                ]);
            }

            return $request->load(['attachments', 'leaveType']);
        });
        $request->holidayDates = $holidayDates;

        // Outside the transaction — a notification that fails to write is
        // never worth rolling back an already-submitted request over.
        $this->notifyApprovers($request, $this->flow->submitRecipients(
            $request,
            fn () => $this->notifications->usersWithPermission(Permissions::LEAVE_REQUESTS_APPROVE),
        ));

        return $request;
    }

    /**
     * "Waiting for your approval" — on submit, and again for each next
     * step's group of an approval flow (see ApprovalFlow::approve()).
     *
     * @param  Collection<int, User>  $recipients
     */
    public function notifyApprovers(LeaveRequest $request, Collection $recipients): void
    {
        $this->notifications->notifyMany(
            $recipients,
            NotificationType::LEAVE_REQUEST_SUBMITTED,
            ['student_id' => $request->student_id, 'staff_id' => $request->staff_id, 'student_name' => $request->requesterName(), 'leave_request_id' => $request->id],
            link: '/admin/approvals/queue',
        );
    }

    public function approve(LeaveRequest $request, User $admin): LeaveRequest
    {
        $request = DB::connection('tenant')->transaction(function () use ($request, $admin) {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== LeaveRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This leave request has already been decided.']);
            }

            // A staff-owned request has no enrollments/attendance to mark
            // Excused — approving one is just the status change below.
            if ($request->student_id !== null) {
                $this->applyToAttendance($request, $admin);
            }

            $request->update([
                'status' => LeaveRequest::STATUS_APPROVED,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });

        $this->notifyOnApproval($request);

        return $request;
    }

    /**
     * A student-owned request notifies: the student themselves, whoever
     * teaches them (see Student::teacherIds() — teacher and assistant
     * teacher alike), and every Staff-role account (this school's front-desk/
     * registration staff — see Role::STAFF's own docblock; there's no
     * separate "Receptionist" concept to target more narrowly than that). A
     * staff-owned request just notifies the requester themselves — there's
     * no "who teaches this staff member" equivalent to notify.
     */
    private function notifyOnApproval(LeaveRequest $request): void
    {
        $requesterName = $request->requesterName();
        $data = ['student_id' => $request->student_id, 'staff_id' => $request->staff_id, 'student_name' => $requesterName, 'leave_request_id' => $request->id];
        $link = '/admin/approvals/my-requests';

        if ($request->staff_id !== null) {
            $staffUser = $request->staff?->user;
            if ($staffUser !== null) {
                $this->notifications->notifyMany(collect([$staffUser]), NotificationType::LEAVE_REQUEST_APPROVED, $data, $link);
            }

            return;
        }

        $student = $request->student;
        $recipients = collect();

        if ($student->user !== null) {
            $recipients->push($student->user);
        }

        $teacherUserIds = Staff::query()->whereIn('id', $student->teacherIds())->pluck('user_id')->filter();
        $recipients = $recipients->merge(User::query()->whereIn('id', $teacherUserIds)->get());

        $recipients = $recipients->merge($this->notifications->usersWithRole(Role::STAFF));

        $this->notifications->notifyMany($recipients, NotificationType::LEAVE_REQUEST_APPROVED, $data, $link);
    }

    public function reject(LeaveRequest $request, string $reason, User $admin): LeaveRequest
    {
        $request = DB::connection('tenant')->transaction(function () use ($request, $reason, $admin) {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== LeaveRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This leave request has already been decided.']);
            }

            // Nothing should be marked for a request that was never approved,
            // but an approval cut off part-way once left Excused marks behind.
            if ($request->student_id !== null) {
                $this->removeExcusedMarks($request, $this->datesBetween($request->from_date->toDateString(), $request->to_date->toDateString()));
            }

            $request->update([
                'status' => LeaveRequest::STATUS_REJECTED,
                'decision_reason' => $reason,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });

        // Only the requester — unlike an approval, a rejection changes
        // nothing their teachers or the front desk need to act on.
        $requesterUser = $request->staff_id !== null ? $request->staff?->user : $request->student?->user;
        if ($requesterUser !== null) {
            $this->notifications->notifyMany(collect([$requesterUser]), NotificationType::LEAVE_REQUEST_REJECTED, [
                'student_id' => $request->student_id,
                'staff_id' => $request->staff_id,
                'student_name' => $request->requesterName(),
                'leave_request_id' => $request->id,
                'reason' => $reason,
            ], link: '/admin/approvals/my-requests');
        }

        return $request;
    }

    /**
     * Staff take back an approved student permission — the student came back
     * early, say. It becomes Cancelled with the reason, the Excused marks its
     * approval wrote are removed (a mark a teacher has since changed stays),
     * so those days stop counting as missed and the student can ask again
     * for the days they really were away. A staff request has a leave
     * balance and payroll behind it, so it can't be cancelled here.
     */
    public function cancel(LeaveRequest $request, string $reason, User $admin): LeaveRequest
    {
        $request = DB::connection('tenant')->transaction(function () use ($request, $reason, $admin) {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->student_id === null) {
                throw ValidationException::withMessages(['status' => 'Only a student permission can be cancelled.']);
            }
            if ($request->status !== LeaveRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Only an approved request can be cancelled.']);
            }

            $this->removeExcusedMarks($request, $this->datesBetween($request->from_date->toDateString(), $request->to_date->toDateString()));
            $request->update([
                'status' => LeaveRequest::STATUS_CANCELLED,
                'decision_reason' => $reason,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });

        // The same people the approval told — the Excused marks they saw are gone.
        $student = $request->student;
        $recipients = collect($student?->user !== null ? [$student->user] : []);
        if ($student !== null) {
            $teacherUserIds = Staff::query()->whereIn('id', $student->teacherIds())->pluck('user_id')->filter();
            $recipients = $recipients->merge(User::query()->whereIn('id', $teacherUserIds)->get());
        }
        $recipients = $recipients->merge($this->notifications->usersWithRole(Role::STAFF));

        $this->notifications->notifyMany($recipients, NotificationType::LEAVE_REQUEST_CANCELLED_BY_SCHOOL, [
            'student_id' => $request->student_id,
            'staff_id' => null,
            'student_name' => $request->requesterName(),
            'leave_request_id' => $request->id,
            'reason' => $reason,
        ], link: '/admin/approvals/my-requests');

        return $request;
    }

    public function destroyAttachment(LeaveRequest $request, int $attachmentId): void
    {
        $attachment = $request->attachments()->whereKey($attachmentId)->firstOrFail();

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();
    }

    /**
     * Marks every date in the request's range as Excused in the request's
     * course, but only for a class actually meeting that day — a student's active enrollments each
     * have their own weekly schedule (ClassSchedule), and a leave request
     * covering, say, a whole week should not manufacture an attendance row
     * for a day the class never had a session on.
     */
    private function applyToAttendance(LeaveRequest $request, User $admin): void
    {
        // Just the course it's for; a request from before the form asked
        // for one still covers every active course.
        $enrollments = $request->student->enrollments()
            ->active()
            ->when($request->enrollment_id !== null, fn ($query) => $query->whereKey($request->enrollment_id))
            ->with('transferHistories')
            ->get();

        /** @var array<int, SchoolClass|null> $classes */
        $classes = [];
        // Nobody is marked on a holiday — there's no class to be excused from.
        $holidayDates = $this->holidayDates($request->from_date->toDateString(), $request->to_date->toDateString());

        foreach ($enrollments as $enrollment) {
            foreach (CarbonPeriod::create($request->from_date, $request->to_date) as $date) {
                if (in_array($date->toDateString(), $holidayDates, true)) {
                    continue;
                }

                // The class the student was in on that day — a leave for an
                // earlier date still lands in the class they had back then.
                $classId = $enrollment->placementOn($date->toDateString())['class_id'];
                if ($classId === null) {
                    continue;
                }

                $class = $classes[$classId] ??= SchoolClass::query()->with('schedules')->find($classId);
                if ($class === null || ! in_array($date->isoWeekday(), $class->schedules->pluck('day_of_week')->all(), true)) {
                    continue;
                }

                $this->attendance->recordForClass($class, $date->toDateString(), [[
                    'enrollment_id' => $enrollment->id,
                    'status' => AttendanceStatus::EXCUSED,
                    'remarks' => "Approved leave request #{$request->id}: {$request->reason}",
                ]], $admin);
            }
        }
    }

    /**
     * A holiday was added (or moved) — student permissions it now covers
     * aren't needed any more. A waiting or approved request whose every day
     * is a holiday becomes Cancelled (its Excused marks removed, the student
     * and their teachers told); an approved one only partly covered keeps
     * its other days and just loses the marks on the holiday's days.
     *
     * @return int how many requests were cancelled
     */
    public function cancelForHoliday(Holiday $holiday, User $admin): int
    {
        $from = $holiday->start_date->toDateString();
        $to = $holiday->end_date->toDateString();

        $affected = LeaveRequest::query()
            ->whereNotNull('student_id')
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED])
            ->whereDate('from_date', '<=', $to)
            ->whereDate('to_date', '>=', $from)
            ->pluck('id');

        $cancelled = [];
        foreach ($affected as $id) {
            $result = DB::connection('tenant')->transaction(function () use ($id, $holiday, $admin, $from, $to) {
                /** @var LeaveRequest|null $request */
                $request = LeaveRequest::query()->whereKey($id)->lockForUpdate()->first();
                if ($request === null || ! in_array($request->status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED], true)) {
                    return null;
                }

                $requestFrom = $request->from_date->toDateString();
                $requestTo = $request->to_date->toDateString();
                $holidayDates = $this->holidayDates($requestFrom, $requestTo);

                if (count($holidayDates) < count($this->datesBetween($requestFrom, $requestTo))) {
                    if ($request->status === LeaveRequest::STATUS_APPROVED) {
                        $this->removeExcusedMarks($request, array_values(array_filter($holidayDates, fn (string $d) => $d >= $from && $d <= $to)));
                    }

                    return null;
                }

                $this->removeExcusedMarks($request, $this->datesBetween($requestFrom, $requestTo));
                $request->update([
                    'status' => LeaveRequest::STATUS_CANCELLED,
                    'decision_reason' => __('Cancelled — holiday: :name', ['name' => $holiday->name]),
                    'decided_by' => $admin->getKey(),
                    'decided_at' => now(),
                ]);

                return $request;
            });

            if ($result !== null) {
                $cancelled[] = $result;
            }
        }

        // Outside the transactions, like every other notification here.
        foreach ($cancelled as $request) {
            $this->notifyCancelled($request, $holiday);
        }

        return count($cancelled);
    }

    /** The student and their teachers — the Excused marks they saw are gone. */
    private function notifyCancelled(LeaveRequest $request, Holiday $holiday): void
    {
        $student = $request->student;
        if ($student === null) {
            return;
        }

        $recipients = collect($student->user !== null ? [$student->user] : []);
        $teacherUserIds = Staff::query()->whereIn('id', $student->teacherIds())->pluck('user_id')->filter();
        $recipients = $recipients->merge(User::query()->whereIn('id', $teacherUserIds)->get());

        $this->notifications->notifyMany($recipients, NotificationType::LEAVE_REQUEST_CANCELLED, [
            'student_id' => $request->student_id,
            'staff_id' => null,
            'student_name' => $request->requesterName(),
            'leave_request_id' => $request->id,
            'holiday' => $holiday->name,
        ], link: '/admin/approvals/my-requests');
    }

    /**
     * Only the Excused marks this request's approval wrote (see
     * applyToAttendance()) — a mark a teacher has since changed stays.
     *
     * @param  list<string>  $dates
     */
    private function removeExcusedMarks(LeaveRequest $request, array $dates): void
    {
        if ($dates === []) {
            return;
        }

        AttendanceRecord::query()
            ->where('student_id', $request->student_id)
            ->where('status', AttendanceStatus::EXCUSED)
            ->where('remarks', 'like', "Approved leave request #{$request->id}:%")
            ->where(function ($query) use ($dates) {
                foreach ($dates as $date) {
                    $query->orWhereDate('date', $date);
                }
            })
            ->delete();
    }

    /**
     * A student asks before class, never during or after it: no past date,
     * and for today only until the class starts (the time in on the form,
     * else the class's start time today). A day the class doesn't meet
     * today can still be asked for.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardNotStarted(Student $student, array $data): void
    {
        $now = CarbonImmutable::now($this->context->get()?->timezone ?: 'Asia/Phnom_Penh');
        $from = substr((string) $data['from_date'], 0, 10);
        $refuse = fn () => throw ValidationException::withMessages([
            'from_date' => __('Ask for permission before class starts. This class has already started or ended.'),
        ]);

        if ($from < $now->toDateString()) {
            $refuse();
        }
        if ($from > $now->toDateString()) {
            return;
        }

        $start = filled($data['from_time'] ?? null) ? substr((string) $data['from_time'], 0, 5) : $this->classStartToday($student, $data['enrollment_id'] ?? null, $now);
        if ($start !== null && $now->format('H:i') >= $start) {
            $refuse();
        }
    }

    /** 'H:i' the student's class (this course, else any active one) starts today, or null when none meets today. */
    private function classStartToday(Student $student, mixed $enrollmentId, CarbonImmutable $now): ?string
    {
        $enrollments = $student->enrollments()->active()
            ->when($enrollmentId !== null, fn ($query) => $query->whereKey($enrollmentId))
            ->with('transferHistories')
            ->get();

        $starts = [];
        foreach ($enrollments as $enrollment) {
            $classId = $enrollment->placementOn($now->toDateString())['class_id'];
            $class = $classId !== null ? SchoolClass::query()->with('schedules')->find($classId) : null;
            foreach ($class?->schedules ?? [] as $schedule) {
                if ((int) $schedule->day_of_week === $now->isoWeekday()) {
                    $starts[] = substr((string) $schedule->start_time, 0, 5);
                }
            }
        }

        return $starts === [] ? null : min($starts);
    }

    /** Missed hours (absent + permission + late) a student may reach; a request that would go above it is refused. */
    public const STUDENT_ABSENCE_LIMIT_HOURS = 6;

    /** The longest a student may ask permission for in one request, in days (from and to counted). */
    public const STUDENT_MAX_DAYS = 7;

    private function guardMaxDays(string $from, string $to): void
    {
        $start = CarbonImmutable::parse(substr($from, 0, 10));
        $end = CarbonImmutable::parse(substr($to, 0, 10));

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['to_date' => __('The end date must be on or after the start date.')]);
        }

        if ($start->diffInDays($end) + 1 > self::STUDENT_MAX_DAYS) {
            throw ValidationException::withMessages([
                'to_date' => __('A permission can cover at most :days days. Please check the dates.', ['days' => self::STUDENT_MAX_DAYS]),
            ]);
        }
    }

    /**
     * Refuse a student's request when the course they picked would go above
     * STUDENT_ABSENCE_LIMIT_HOURS missed: what is already missed — absent +
     * permission sessions plus late minutes, the same total as the home
     * screen's "absent hours" card (MyAttendanceController::hoursSummary()) —
     * plus the class hours this request and their other waiting requests
     * would excuse. E.g. 4 hours missed + a 3-hour request = 7: refused.
     */
    private function guardAbsenceLimit(Student $student, mixed $enrollmentId, string $from, string $to): void
    {
        if ($enrollmentId === null) {
            return;
        }

        $row = collect($this->attendance->summarize(null, null, null, $student->id))
            ->firstWhere('enrollment_id', (int) $enrollmentId);

        if ($row === null) {
            return;
        }

        $missed = $row['permission_hours'] + $row['absent_hours'] + $row['late_minutes'] / 60;
        $asked = $this->requestedMinutes($student, (int) $enrollmentId, $from, $to) / 60;

        if ($missed + $asked > self::STUDENT_ABSENCE_LIMIT_HOURS + 0.001) {
            throw ValidationException::withMessages([
                'absence_limit' => 'អ្នកមិនអាចសច្បាប់បន្តបានទេ បើនៅតែបន្តអវត្តមានសាលានឹងបញ្ឈប់ការសិក្សាតែម្តង',
            ]);
        }
    }

    /**
     * Class minutes a new request (from–to) and the student's waiting
     * requests for this course would excuse once approved, counted as
     * applyToAttendance() marks them: each date once, only days the class
     * meets, not holidays, and not days already marked Absent or Excused
     * (those are already in the missed hours).
     */
    private function requestedMinutes(Student $student, int $enrollmentId, string $from, string $to): int
    {
        $enrollment = $student->enrollments()->with('transferHistories')->whereKey($enrollmentId)->first();
        if ($enrollment === null) {
            return 0;
        }

        $ranges = LeaveRequest::query()
            ->where('student_id', $student->id)
            ->where('enrollment_id', $enrollmentId)
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->get(['from_date', 'to_date'])
            ->map(fn (LeaveRequest $r) => [$r->from_date->toDateString(), $r->to_date->toDateString()])
            ->push([substr($from, 0, 10), substr($to, 0, 10)]);

        $dates = [];
        foreach ($ranges as [$start, $end]) {
            if ($end < $start || CarbonPeriod::create($start, $end)->count() > 366) {
                continue;
            }
            $holidays = $this->holidayDates($start, $end);
            foreach ($this->datesBetween($start, $end) as $date) {
                if (! in_array($date, $holidays, true)) {
                    $dates[$date] = true;
                }
            }
        }
        if ($dates === []) {
            return 0;
        }

        $alreadyMissed = AttendanceRecord::query()
            ->where('enrollment_id', $enrollmentId)
            ->whereIn('status', [AttendanceStatus::ABSENT, AttendanceStatus::EXCUSED])
            ->whereIn('date', array_keys($dates))
            ->pluck('date')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->all();

        /** @var array<int, SchoolClass|null> $classes */
        $classes = [];
        $minutes = 0;
        foreach (array_keys($dates) as $date) {
            if (in_array($date, $alreadyMissed, true)) {
                continue;
            }
            // The class the student was in that day, if it meets that weekday.
            $classId = $enrollment->placementOn($date)['class_id'];
            if ($classId === null) {
                continue;
            }
            $class = $classes[$classId] ??= SchoolClass::query()->with('schedules')->find($classId);
            $weekday = CarbonImmutable::parse($date)->isoWeekday();
            if ($class === null || ! in_array($weekday, $class->schedules->pluck('day_of_week')->all(), true)) {
                continue;
            }
            $minutes += $class->minutesOn($weekday);
        }

        return $minutes;
    }

    /**
     * The dates between two days (inclusive) that any holiday covers.
     *
     * @return list<string> Y-m-d
     */
    private function holidayDates(string $from, string $to): array
    {
        $holidays = Holiday::query()->whereDate('end_date', '>=', $from)->whereDate('start_date', '<=', $to)->get();

        return array_values(array_filter(
            $this->datesBetween($from, $to),
            fn (string $date) => $holidays->contains(fn (Holiday $h) => $h->start_date->toDateString() <= $date && $h->end_date->toDateString() >= $date),
        ));
    }

    /** "Khmer New Year, Visak Bochea" — the holidays between two days. */
    private function holidayNames(string $from, string $to): string
    {
        return Holiday::query()->whereDate('end_date', '>=', $from)->whereDate('start_date', '<=', $to)->orderBy('start_date')->pluck('name')->implode(', ');
    }

    /** @return list<string> Y-m-d, inclusive */
    private function datesBetween(string $from, string $to): array
    {
        $dates = [];
        foreach (CarbonPeriod::create(substr($from, 0, 10), substr($to, 0, 10)) as $date) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }
}

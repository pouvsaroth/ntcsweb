<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\LeaveRequest;
use App\Models\Student;
use App\Models\User;
use App\Support\Academic\AttendanceStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A student's own leave/permission request — see LeaveRequest's own
 * docblock for why this is a separate entity from AttendanceRecord.
 * approve() is the one place that ever writes attendance on a student's
 * behalf here, and it does so entirely through the existing, unmodified
 * AttendanceService::recordForClass() — one call per (class, date) actually
 * affected, not a hand-rolled upsert.
 */
final class LeaveRequestService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * @param  array{from_date:string, to_date:string, from_time?:string|null, to_time?:string|null, reason:string, attachments?:list<UploadedFile>}  $data
     */
    public function submit(Student $student, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($student, $data) {
            $request = LeaveRequest::query()->create([
                'student_id' => $student->id,
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'from_time' => $data['from_time'] ?? null,
                'to_time' => $data['to_time'] ?? null,
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

            return $request->load('attachments');
        });
    }

    public function approve(LeaveRequest $request, User $admin): LeaveRequest
    {
        return DB::transaction(function () use ($request, $admin) {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== LeaveRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This leave request has already been decided.']);
            }

            $this->applyToAttendance($request, $admin);

            $request->update([
                'status' => LeaveRequest::STATUS_APPROVED,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });
    }

    public function reject(LeaveRequest $request, string $reason, User $admin): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reason, $admin) {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== LeaveRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This leave request has already been decided.']);
            }

            $request->update([
                'status' => LeaveRequest::STATUS_REJECTED,
                'decision_reason' => $reason,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });
    }

    public function destroyAttachment(LeaveRequest $request, int $attachmentId): void
    {
        $attachment = $request->attachments()->whereKey($attachmentId)->firstOrFail();

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();
    }

    /**
     * Marks every date in the request's range as Excused, but only for a
     * class actually meeting that day — a student's active enrollments each
     * have their own weekly schedule (ClassSchedule), and a leave request
     * covering, say, a whole week should not manufacture an attendance row
     * for a day the class never had a session on.
     */
    private function applyToAttendance(LeaveRequest $request, User $admin): void
    {
        $enrollments = $request->student->enrollments()
            ->active()
            ->with('schoolClass.schedules')
            ->get();

        foreach ($enrollments as $enrollment) {
            $class = $enrollment->schoolClass;
            if ($class === null) {
                continue;
            }

            $meetingDays = $class->schedules->pluck('day_of_week')->all();
            if ($meetingDays === []) {
                continue;
            }

            foreach (CarbonPeriod::create($request->from_date, $request->to_date) as $date) {
                if (! in_array($date->isoWeekday(), $meetingDays, true)) {
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
}

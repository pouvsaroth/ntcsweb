<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\ClassChangeRequest;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A student's request to change class (eApprovals > My Request > Request to
 * change class).
 *
 * - The student picks one of their active enrollments, the class they want
 *   (any open class — upcoming or active — other than the current one) and
 *   the day they start there (ថ្ងៃចាប់ផ្តើមរៀន), and submits (ដាក់ស្នើ).
 * - It waits in Approvals: an Approval Flow when the school set one up for
 *   "Class change request", else holders of `class-change-requests.approve`.
 * - Approving moves the same enrollment to the new class
 *   (EnrollmentService::transferClass, any program): no new enrollment, the
 *   enrollment's code, dates, course and fee stay; only the class changes
 *   (and its seat, which belonged to the old room). The transfer history
 *   row keeps the start date and the request.
 */
final class ClassChangeRequestService
{
    /** Classes a student may ask to move into. */
    private const OPEN = [SchoolClass::STATUS_UPCOMING, SchoolClass::STATUS_ACTIVE];

    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly ApprovalFlow $flow,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * The form: the student's active enrollments, and the open classes.
     *
     * @return array{enrollments: list<array<string, mixed>>, classes: list<array<string, mixed>>}
     */
    public function options(Student $student): array
    {
        $enrollments = Enrollment::query()->with(['schoolClass:id,name', 'coursePackage:id,name'])
            ->where('student_id', $student->getKey())->where('status', Enrollment::STATUS_ACTIVE)
            ->orderByDesc('id')->get();
        $waiting = ClassChangeRequest::query()->whereIn('enrollment_id', $enrollments->modelKeys() ?: [0])
            ->where('status', ClassChangeRequest::STATUS_PENDING)->pluck('enrollment_id')->all();

        $classes = SchoolClass::query()->with(['academicYear:id,name', 'gradeLevel:id,name'])
            ->whereIn('status', self::OPEN)->orderBy('name')
            ->get(['id', 'name', 'status', 'academic_year_id', 'grade_level_id', 'start_date']);

        return [
            'enrollments' => $enrollments->map(fn (Enrollment $e) => [
                'id' => $e->id,
                'code' => $e->enrollments_code,
                'class' => $e->schoolClass ? ['id' => $e->schoolClass->id, 'name' => $e->schoolClass->name] : null,
                'course' => $e->coursePackage?->name,
                'has_pending_request' => in_array($e->id, $waiting, true),
            ])->values()->all(),
            'classes' => $classes->map(fn (SchoolClass $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status,
                'academic_year' => $c->academicYear?->name,
                'grade_level' => $c->gradeLevel?->name,
                'start_date' => $c->start_date?->toDateString(),
            ])->values()->all(),
        ];
    }

    /** @param  array{enrollment_id: int, to_class_id: int, start_date: string, reason?: string|null}  $data */
    public function submit(Student $student, array $data, User $by): ClassChangeRequest
    {
        $request = DB::connection('tenant')->transaction(function () use ($student, $data, $by) {
            $enrollment = Enrollment::query()->whereKey($data['enrollment_id'])->where('student_id', $student->getKey())->lockForUpdate()->first();
            if ($enrollment === null || $enrollment->status !== Enrollment::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['enrollment_id' => __('Choose one of your active classes.')]);
            }
            $to = SchoolClass::query()->whereKey($data['to_class_id'])->whereIn('status', self::OPEN)->first();
            if ($to === null) {
                throw ValidationException::withMessages(['to_class_id' => __('This class is not open.')]);
            }
            if ((int) $to->getKey() === (int) $enrollment->class_id) {
                throw ValidationException::withMessages(['to_class_id' => __('You are already in this class.')]);
            }
            if (ClassChangeRequest::query()->where('enrollment_id', $enrollment->getKey())->where('status', ClassChangeRequest::STATUS_PENDING)->exists()) {
                throw ValidationException::withMessages(['enrollment_id' => __('A request to change this class is already waiting.')]);
            }

            return ClassChangeRequest::query()->create([
                'student_id' => $student->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'from_class_id' => $enrollment->class_id,
                'to_class_id' => $to->getKey(),
                'start_date' => $data['start_date'],
                'reason' => $data['reason'] ?? null,
                'status' => ClassChangeRequest::STATUS_PENDING,
                'requested_by' => $by->getKey(),
            ]);
        });

        $this->notifications->notifyMany(
            $this->flow->submitRecipients($request, fn () => $this->notifications->usersWithPermission(Permissions::CLASS_CHANGE_REQUESTS_APPROVE)),
            NotificationType::CLASS_CHANGE_REQUEST_SUBMITTED, $this->data($request, $by), link: '/admin/approvals/queue',
        );

        return $request;
    }

    /** The student takes back a request still waiting. */
    public function withdraw(ClassChangeRequest $request, Student $student): void
    {
        if ((int) $request->student_id !== (int) $student->getKey()) {
            abort(404);
        }
        if ($request->status !== ClassChangeRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => __('This has already been decided.')]);
        }
        $request->delete();
    }

    public function approve(ClassChangeRequest $request, User $by): ClassChangeRequest
    {
        $this->flow->authorizeDecision($request, $by, 'approve');

        return $this->flow->approve(
            $request,
            $by,
            function (ClassChangeRequest $doc) use ($by) {
                $doc = DB::connection('tenant')->transaction(function () use ($doc, $by) {
                    $locked = ClassChangeRequest::query()->whereKey($doc->getKey())->lockForUpdate()->firstOrFail();
                    $this->assertPending($locked);
                    $enrollment = Enrollment::query()->findOrFail($locked->enrollment_id);
                    if ((int) $enrollment->class_id !== (int) $locked->to_class_id) {
                        $this->enrollments->transferClass(
                            $enrollment,
                            SchoolClass::query()->findOrFail($locked->to_class_id),
                            $by,
                            anyProgram: true,
                            history: ['start_date' => $locked->start_date?->toDateString(), 'class_change_request_id' => $locked->id],
                        );
                    }
                    $locked->forceFill(['status' => ClassChangeRequest::STATUS_APPROVED, 'decided_by' => $by->getKey(), 'decided_at' => now()])->save();

                    return $locked;
                });
                $this->tellStudent($doc, NotificationType::CLASS_CHANGE_REQUEST_APPROVED, $this->data($doc, $by));

                return $doc;
            },
            fn (ClassChangeRequest $doc, Collection $next) => $this->notifications->notifyMany($next, NotificationType::CLASS_CHANGE_REQUEST_SUBMITTED, $this->data($doc, null), link: '/admin/approvals/queue'),
        );
    }

    public function reject(ClassChangeRequest $request, string $reason, User $by): ClassChangeRequest
    {
        $this->flow->authorizeDecision($request, $by, 'reject');

        $request = DB::connection('tenant')->transaction(function () use ($request, $reason, $by) {
            $locked = ClassChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);
            $locked->forceFill(['status' => ClassChangeRequest::STATUS_REJECTED, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'decision_reason' => $reason])->save();

            return $locked;
        });
        $this->tellStudent($request, NotificationType::CLASS_CHANGE_REQUEST_REJECTED, $this->data($request, $by) + ['reason' => $reason]);

        return $request;
    }

    /** @return array<string, mixed> */
    public function present(ClassChangeRequest $r, ?User $viewer = null): array
    {
        $r->loadMissing(['student:id,student_code,first_name,last_name,user_id', 'fromClass:id,name', 'toClass:id,name', 'enrollment:id,enrollments_code,course_package_id', 'enrollment.coursePackage:id,name', 'decidedBy:id,name']);

        return [
            'id' => $r->id,
            'reference' => $r->reference(),
            'student' => $r->student ? ['id' => $r->student->id, 'code' => $r->student->student_code, 'name' => $r->student->fullName()] : null,
            'enrollment' => ['id' => $r->enrollment_id, 'code' => $r->enrollment?->enrollments_code, 'course' => $r->enrollment?->coursePackage?->name],
            'from_class' => $r->fromClass ? ['id' => $r->fromClass->id, 'name' => $r->fromClass->name] : null,
            'to_class' => $r->toClass ? ['id' => $r->toClass->id, 'name' => $r->toClass->name] : null,
            'start_date' => $r->start_date?->toDateString(),
            'reason' => $r->reason,
            'status' => $r->status,
            'decided_by' => $r->decidedBy?->name,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_reason' => $r->decision_reason,
            'created_at' => $r->created_at?->toIso8601String(),
            'approval_flow' => $r->relationLoaded('approvalFlow') ? $r->getRelation('approvalFlow') : null,
            'can_approve' => $viewer !== null && $r->status === ClassChangeRequest::STATUS_PENDING && $this->flow->mayDecide($r, $viewer, 'approve'),
            'can_reject' => $viewer !== null && $r->status === ClassChangeRequest::STATUS_PENDING && $this->flow->mayDecide($r, $viewer, 'reject'),
        ];
    }

    private function assertPending(ClassChangeRequest $r): void
    {
        if ($r->status !== ClassChangeRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => __('This has already been decided.')]);
        }
    }

    private function tellStudent(ClassChangeRequest $r, string $type, array $data): void
    {
        $user = $r->student?->user ?? ($r->requested_by ? User::query()->find($r->requested_by) : null);
        if ($user !== null) {
            $this->notifications->notify($user, $type, $data, link: '/admin/approvals/my-requests');
        }
    }

    /** @return array<string, mixed> */
    private function data(ClassChangeRequest $r, ?User $by): array
    {
        $r->loadMissing(['student:id,student_code,first_name,last_name,user_id', 'fromClass:id,name', 'toClass:id,name']);

        return [
            'class_change_request_id' => $r->id,
            'student_name' => $r->student?->fullName(),
            'from_class' => $r->fromClass?->name,
            'to_class' => $r->toClass?->name,
            'start_date' => $r->start_date?->toDateString(),
            'by_name' => $by?->name,
        ];
    }
}

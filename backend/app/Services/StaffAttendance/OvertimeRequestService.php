<?php

declare(strict_types=1);

namespace App\Services\StaffAttendance;

use App\Models\OvertimeRequest;
use App\Models\Staff;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Overtime claims — submitted by the staff member (or HR for them) straight
 * into E-Approvals, decided there. Same shape as ManpowerRequestService.
 */
final class OvertimeRequestService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ApprovalFlow $flow,
    ) {}

    public function submit(Staff $staff, string $date, int $minutes, string $reason, User $by): OvertimeRequest
    {
        $open = OvertimeRequest::query()->where('staff_id', $staff->id)->whereDate('date', $date)
            ->whereIn('status', [OvertimeRequest::STATUS_PENDING, OvertimeRequest::STATUS_APPROVED])->exists();
        if ($open) {
            throw ValidationException::withMessages(['date' => __('Overtime for this day has already been requested.')]);
        }

        $request = OvertimeRequest::query()->create([
            'staff_id' => $staff->id,
            'date' => $date,
            'minutes' => $minutes,
            'reason' => $reason,
            'requested_by' => $by->getKey(),
        ]);

        $this->notifyApprovers($request, $this->flow->submitRecipients(
            $request,
            fn () => $this->notifications->usersWithPermission(Permissions::OVERTIME_REQUESTS_APPROVE),
        ));

        return $request;
    }

    /** @param  Collection<int, User>  $recipients */
    public function notifyApprovers(OvertimeRequest $request, Collection $recipients): void
    {
        $this->notifications->notifyMany($recipients, NotificationType::OVERTIME_REQUEST_SUBMITTED, $this->data($request), link: '/admin/approvals/queue');
    }

    public function approve(OvertimeRequest $request, User $admin): OvertimeRequest
    {
        $request = $this->decide($request, $admin, OvertimeRequest::STATUS_APPROVED);
        $this->notifyStaff($request, NotificationType::OVERTIME_REQUEST_APPROVED);

        return $request;
    }

    public function reject(OvertimeRequest $request, string $reason, User $admin): OvertimeRequest
    {
        $request = $this->decide($request, $admin, OvertimeRequest::STATUS_REJECTED, $reason);
        $this->notifyStaff($request, NotificationType::OVERTIME_REQUEST_REJECTED, ['reason' => $reason]);

        return $request;
    }

    private function decide(OvertimeRequest $request, User $admin, string $status, ?string $reason = null): OvertimeRequest
    {
        return DB::connection('tenant')->transaction(function () use ($request, $admin, $status, $reason) {
            /** @var OvertimeRequest $request */
            $request = OvertimeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== OvertimeRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This overtime request has already been decided.']);
            }

            $request->update(['status' => $status, 'decision_reason' => $reason, 'decided_by' => $admin->getKey(), 'decided_at' => now()]);

            return $request->fresh();
        });
    }

    /** @param  array<string, mixed>  $extra */
    private function notifyStaff(OvertimeRequest $request, string $type, array $extra = []): void
    {
        $user = $request->staff?->user;
        if ($user !== null) {
            $this->notifications->notify($user, $type, [...$this->data($request), ...$extra], link: '/admin/my-check-in');
        }
    }

    /** @return array<string, mixed> */
    private function data(OvertimeRequest $request): array
    {
        return [
            'overtime_request_id' => $request->id,
            'staff_name' => $request->staff?->fullName(),
            'date' => $request->date?->format('d-m-Y'),
            'hours' => round($request->minutes / 60, 2),
        ];
    }
}

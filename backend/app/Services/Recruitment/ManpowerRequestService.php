<?php

declare(strict_types=1);

namespace App\Services\Recruitment;

use App\Models\ManpowerRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HRM > Recruitment > Manpower request — submitted straight into the
 * E-Approvals queue, decided there. Same shape as ResignationRequestService.
 */
final class ManpowerRequestService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ApprovalFlow $flow,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(User $requester, array $data): ManpowerRequest
    {
        $request = ManpowerRequest::query()->create([
            ...$data,
            'requested_by' => $requester->getKey(),
            'status' => ManpowerRequest::STATUS_PENDING,
        ]);

        // Outside the create — a notification that fails to write is never
        // worth losing an already-submitted request over.
        $this->notifyApprovers($request, $this->flow->submitRecipients(
            $request,
            fn () => $this->notifications->usersWithPermission(Permissions::MANPOWER_REQUESTS_APPROVE),
        ));

        return $request;
    }

    /**
     * "Waiting for your approval" — on submit, and again for each next
     * step's group of an approval flow (see ApprovalFlow::approve()).
     *
     * @param  Collection<int, User>  $recipients
     */
    public function notifyApprovers(ManpowerRequest $request, Collection $recipients): void
    {
        $this->notifications->notifyMany(
            $recipients,
            NotificationType::MANPOWER_REQUEST_SUBMITTED,
            $this->notificationData($request),
            link: '/admin/approvals/queue',
        );
    }

    public function approve(ManpowerRequest $request, User $admin): ManpowerRequest
    {
        $request = $this->decide($request, $admin, ManpowerRequest::STATUS_APPROVED);

        $this->notifyRequester($request, NotificationType::MANPOWER_REQUEST_APPROVED);

        return $request;
    }

    public function reject(ManpowerRequest $request, string $reason, User $admin): ManpowerRequest
    {
        $request = $this->decide($request, $admin, ManpowerRequest::STATUS_REJECTED, $reason);

        $this->notifyRequester($request, NotificationType::MANPOWER_REQUEST_REJECTED, ['reason' => $reason]);

        return $request;
    }

    private function decide(ManpowerRequest $request, User $admin, string $status, ?string $reason = null): ManpowerRequest
    {
        return DB::connection('tenant')->transaction(function () use ($request, $admin, $status, $reason) {
            /** @var ManpowerRequest $request */
            $request = ManpowerRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== ManpowerRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This manpower request has already been decided.']);
            }

            $request->update([
                'status' => $status,
                'decision_reason' => $reason,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ]);

            return $request->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function notifyRequester(ManpowerRequest $request, string $type, array $extra = []): void
    {
        $requester = $request->requestedBy;
        if ($requester === null) {
            return;
        }

        $this->notifications->notify($requester, $type, [...$this->notificationData($request), ...$extra], link: '/admin/recruitment/manpower-requests');
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationData(ManpowerRequest $request): array
    {
        return [
            'manpower_request_id' => $request->id,
            'reference' => $request->reference(),
            'job_title' => $request->job_title,
            'headcount' => $request->headcount,
            'requester_name' => $request->requestedBy?->name,
        ];
    }
}

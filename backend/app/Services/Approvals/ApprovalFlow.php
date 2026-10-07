<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalGroupMember;
use App\Models\ApprovalStepApproval;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Approvals\DocumentType;
use App\Support\Notifications\NotificationType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approval Flow → Flow Setting, enforced. Sits in front of every Approvals
 * queue item's own approve/reject (LeaveRequestService::approve() etc.):
 *
 * - An item with no flow: nothing changes — whoever holds its approve /
 *   reject permission decides, as before.
 * - An item with a flow: it waits on one step at a time. Any one member of
 *   the current step's group may approve that step (being in the group is
 *   enough — no permission needed) and it moves on to the next step's
 *   group, who are notified — and so is the requester, so they can follow
 *   their request step by step. Approving the last step runs the item's own
 *   approve() (which tells the requester it's approved). Any member of the current step's group may reject it
 *   outright. Nobody else — not even a holder of the approve permission —
 *   can decide it, and a pending item is only listed in the Approvals queue
 *   to the group it's waiting on.
 *
 * The flow is read at decision time, not snapshotted at submission, so
 * changing a flow also moves requests already waiting: they continue from
 * the first step of the new flow they haven't approved yet.
 */
final class ApprovalFlow
{
    /**
     * Statuses still waiting on someone in the flow: `pending`, plus a make-up
     * class request's `approved_to_study` — every step is approved, and the
     * last step's group still confirms the student came (see
     * MakeUpClassRequest's docblock).
     */
    private const AWAITING_STATUSES = ['pending', 'approved_to_study'];

    /**
     * Bumped whenever a flow step or group membership changes (see
     * invalidate()) — this service's caches below are only reused while it
     * hasn't moved, so a long-lived instance (a cached controller, a queue
     * worker) never decides against an outdated flow.
     */
    private static int $version = 0;

    private int $cachedVersion = -1;

    /** @var Collection<string, Collection<int, ApprovalFlowStep>>|null */
    private ?Collection $steps = null;

    /** @var array<int, list<int>> group id -> member user ids */
    private array $members = [];

    /** @var array<string, list<int>> "type:id" -> approved step orders */
    private array $approved = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly NotificationService $notifications,
    ) {}

    public static function invalidate(): void
    {
        self::$version++;
    }

    // --- Reading the flow ---------------------------------------------------------

    /** @return Collection<int, ApprovalFlowStep> ordered by step */
    public function steps(string $type): Collection
    {
        $this->refreshCaches();
        $this->steps ??= ApprovalFlowStep::query()->with('group')->orderBy('step_order')->get()->groupBy('document_type');

        return $this->steps->get($type, collect())->values();
    }

    public function hasFlow(string $type): bool
    {
        return $this->steps($type)->isNotEmpty();
    }

    /** @return list<int> */
    public function memberIds(int $groupId): array
    {
        $this->refreshCaches();
        if (! array_key_exists($groupId, $this->members)) {
            $this->members[$groupId] = ApprovalGroupMember::query()->where('approval_group_id', $groupId)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->members[$groupId];
    }

    /** @return list<int> step orders of this request already approved */
    public function approvedSteps(Model $document): array
    {
        $key = DocumentType::forModel($document).':'.$document->getKey();

        if (! array_key_exists($key, $this->approved)) {
            $this->approved[$key] = ApprovalStepApproval::query()
                ->where('approvable_type', DocumentType::forModel($document))
                ->where('approvable_id', $document->getKey())
                ->pluck('step_order')
                ->map(fn ($order) => (int) $order)
                ->all();
        }

        return $this->approved[$key];
    }

    /**
     * The step this request waits on: the first one not yet approved. If a
     * flow was shortened after some steps were approved, the last step —
     * approving it just finishes the request.
     */
    public function currentStep(Model $document): ?ApprovalFlowStep
    {
        $steps = $this->steps(DocumentType::forModel($document));

        if ($steps->isEmpty()) {
            return null;
        }

        $approved = $this->approvedSteps($document);

        return $steps->first(fn (ApprovalFlowStep $step) => ! in_array($step->step_order, $approved, true)) ?? $steps->last();
    }

    public function isCurrentApprover(Model $document, User $user): bool
    {
        $step = $this->currentStep($document);

        return $step !== null && in_array((int) $user->getKey(), $this->memberIds($step->approval_group_id), true);
    }

    /** Is this user in any step's group of any of these items? */
    public function isApproverFor(User $user, array $types): bool
    {
        foreach ($types as $type) {
            foreach ($this->steps($type) as $step) {
                if (in_array((int) $user->getKey(), $this->memberIds($step->approval_group_id), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> every item this user approves a step of — for the signed-in user's own payload */
    public function typesFor(User $user): array
    {
        return array_values(array_filter(DocumentType::all(), fn (string $type) => $this->isApproverFor($user, [$type])));
    }

    /**
     * For the Approvals queue: where a pending request is in its flow, and
     * whether this user can act on it now. Null when the item has no flow
     * or the request no longer waits on anyone (see AWAITING_STATUSES).
     *
     * @return array{step:int, total:int, group:string|null, can_act:bool}|null
     */
    public function progress(Model $document, ?User $user): ?array
    {
        if (! in_array($document->getAttribute('status'), self::AWAITING_STATUSES, true)) {
            return null;
        }

        $step = $this->currentStep($document);

        if ($step === null) {
            return null;
        }

        $steps = $this->steps(DocumentType::forModel($document));

        return [
            'step' => $steps->search(fn (ApprovalFlowStep $s) => $s->is($step)) + 1,
            'total' => $steps->count(),
            'group' => $step->group?->name,
            'can_act' => $user !== null && $this->isCurrentApprover($document, $user),
        ];
    }

    /**
     * Sets `approvalFlow` (see progress()) on each row of a queue listing,
     * with one query for all their approvals.
     *
     * @param  iterable<Model>  $documents
     */
    public function attachProgress(iterable $documents, ?User $user): void
    {
        $documents = collect($documents);
        $this->preloadApprovals($documents);

        $documents->each(fn (Model $document) => $document->setRelation('approvalFlow', $this->progress($document, $user)));
    }

    // --- Deciding -------------------------------------------------------------------

    /**
     * May this user approve/reject this request? With a flow: only the
     * current step's group. Without: the item's own policy ability.
     */
    public function mayDecide(Model $document, User $user, string $ability): bool
    {
        return $this->hasFlow(DocumentType::forModel($document))
            ? $this->isCurrentApprover($document, $user)
            : $user->can($ability, $document);
    }

    public function authorizeDecision(Model $document, User $user, string $ability): void
    {
        if (! $this->mayDecide($document, $user, $ability)) {
            throw new AuthorizationException('This request is not waiting for your approval.');
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $document
     * @param  callable(TModel): TModel  $finalApprove  the item's own approve, run once the last step is approved
     * @param  callable(TModel, Collection<int, User>): void  $notifyNext  tells the next step's group it's their turn
     * @return TModel
     */
    public function approve(Model $document, User $user, callable $finalApprove, callable $notifyNext): Model
    {
        $type = DocumentType::forModel($document);

        if (! $this->hasFlow($type)) {
            return $finalApprove($document);
        }

        $this->authorizeDecision($document, $user, 'approve');

        $next = DB::connection('tenant')->transaction(function () use ($document, $user, $type) {
            /** @var Model $locked */
            $locked = $document->newQuery()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->getAttribute('status') !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
            }

            // Re-read under the lock: another member of the same group may
            // have approved this step a moment ago, moving it on to a step
            // this user isn't part of.
            $this->forgetApprovals($locked);
            if (! $this->isCurrentApprover($locked, $user)) {
                throw ValidationException::withMessages(['status' => 'This step was already approved by someone else.']);
            }

            $step = $this->currentStep($locked);

            if (! in_array($step->step_order, $this->approvedSteps($locked), true)) {
                ApprovalStepApproval::query()->create([
                    'approvable_type' => $type,
                    'approvable_id' => $locked->getKey(),
                    'step_order' => $step->step_order,
                    'approval_group_id' => $step->approval_group_id,
                    'user_id' => $user->getKey(),
                    'approved_at' => now(),
                ]);
                $this->forgetApprovals($locked);
            }

            $remaining = $this->steps($type)->first(fn (ApprovalFlowStep $s) => ! in_array($s->step_order, $this->approvedSteps($locked), true));

            return [$locked, $remaining];
        });

        [$locked, $remaining] = $next;

        if ($remaining === null) {
            return $finalApprove($locked);
        }

        $notifyNext($locked, $this->usersIn($remaining->approval_group_id));
        $this->notifyRequesterOfStep($locked, $user, $type);

        return $locked;
    }

    /**
     * "Step 1 of 3 approved" — only for a step that isn't the last; the last
     * one is the item's own approve(), which sends its own "approved".
     */
    private function notifyRequesterOfStep(Model $document, User $approver, string $type): void
    {
        $requester = $this->requesterOf($document);

        if ($requester === null || $requester->is($approver)) {
            return;
        }

        $steps = $this->steps($type);
        $approved = $this->approvedSteps($document);

        $this->notifications->notifyMany(collect([$requester]), NotificationType::APPROVAL_STEP_APPROVED, [
            'step' => $steps->filter(fn (ApprovalFlowStep $s) => in_array($s->step_order, $approved, true))->count(),
            'total' => $steps->count(),
            'approver_name' => $approver->name,
        ], link: '/admin/approvals/my-requests');
    }

    /**
     * Whose request this is — the student or staff member it's for, else
     * whoever filed it (a form request or a manpower request).
     */
    private function requesterOf(Model $document): ?User
    {
        foreach (['student', 'staff'] as $relation) {
            if ($document->hasAttribute("{$relation}_id") && $document->getAttribute("{$relation}_id") !== null && method_exists($document, $relation)) {
                $user = $document->{$relation}?->user;
                if ($user instanceof User) {
                    return $user;
                }
            }
        }

        $requestedBy = $document->hasAttribute('requested_by') ? $document->getAttribute('requested_by') : null;

        return $requestedBy !== null ? User::query()->find($requestedBy) : null;
    }

    /**
     * Who is told a new request was submitted: the first step's group when
     * the item has a flow, otherwise whoever `$fallback` returns (the
     * approve-permission holders, as before).
     *
     * @param  callable(): Collection<int, User>  $fallback
     * @return Collection<int, User>
     */
    public function submitRecipients(Model $document, callable $fallback): Collection
    {
        $first = $this->steps(DocumentType::forModel($document))->first();

        return $first !== null ? $this->usersIn($first->approval_group_id) : $fallback();
    }

    // --- The Approvals queue ----------------------------------------------------------

    /**
     * Narrows an item's index query for the Approvals queue: a pending
     * request of an item with a flow is only listed to the group it waits on
     * (plus anyone who already approved a step of it). Everything else is
     * listed to whoever holds the item's view permission, as before. A
     * group member without that permission sees only the requests that
     * involve them.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function scopeQueue(Builder $query, string $modelClass, User $user, bool $canViewAll): void
    {
        $visibleIds = collect();
        $flowTypes = [];
        $plainTypes = [];

        foreach (DocumentType::forModelClass($modelClass) as $type) {
            if (! $this->hasFlow($type)) {
                $plainTypes[] = $type;

                continue;
            }

            $flowTypes[] = $type;
            $pendingQuery = $modelClass::query()->whereIn('status', self::AWAITING_STATUSES);
            DocumentType::constrain($pendingQuery, $type);
            $pending = $pendingQuery->get();
            $this->preloadApprovals($pending);

            $visibleIds = $visibleIds->merge($pending->filter(fn (Model $doc) => $this->isCurrentApprover($doc, $user))->modelKeys());
        }

        $involvedIds = ApprovalStepApproval::query()
            ->whereIn('approvable_type', DocumentType::forModelClass($modelClass))
            ->where('user_id', $user->getKey())
            ->pluck('approvable_id');
        $visibleIds = $visibleIds->merge($involvedIds)->unique()->values()->all();

        $query->where(function (Builder $outer) use ($canViewAll, $flowTypes, $plainTypes, $visibleIds) {
            $outer->whereIn($outer->qualifyColumn('id'), $visibleIds);

            if (! $canViewAll) {
                return;
            }

            // Every decided request, and every pending one of an item with no flow.
            $outer->orWhere($outer->qualifyColumn('status'), '!=', 'pending');
            foreach ($plainTypes as $type) {
                $outer->orWhere(function (Builder $inner) use ($type) {
                    $inner->where($inner->qualifyColumn('status'), 'pending');
                    DocumentType::constrain($inner, $type);
                });
            }
        });
    }

    // --- Internals -----------------------------------------------------------------------

    /** @return Collection<int, User> */
    private function usersIn(int $groupId): Collection
    {
        return User::query()
            ->inTenant($this->context->getOrFail())
            ->active()
            ->whereIn('id', $this->memberIds($groupId))
            ->get();
    }

    /** @param  Collection<int, Model>  $documents */
    private function preloadApprovals(Collection $documents): void
    {
        $byType = $documents->groupBy(fn (Model $doc) => DocumentType::forModel($doc));

        foreach ($byType as $type => $docs) {
            $ids = $docs->map(fn (Model $doc) => $doc->getKey())->all();
            $rows = ApprovalStepApproval::query()->where('approvable_type', $type)->whereIn('approvable_id', $ids)->get(['approvable_id', 'step_order'])->groupBy('approvable_id');

            foreach ($ids as $id) {
                $this->approved["{$type}:{$id}"] = $rows->get($id, collect())->pluck('step_order')->map(fn ($order) => (int) $order)->all();
            }
        }
    }

    private function refreshCaches(): void
    {
        if ($this->cachedVersion !== self::$version) {
            $this->cachedVersion = self::$version;
            $this->steps = null;
            $this->members = [];
            $this->approved = [];
        }
    }

    private function forgetApprovals(Model $document): void
    {
        unset($this->approved[DocumentType::forModel($document).':'.$document->getKey()]);
    }
}

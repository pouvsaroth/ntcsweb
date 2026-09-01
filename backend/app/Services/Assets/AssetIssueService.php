<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetIssue;
use App\Models\User;
use App\Support\Assets\AssetHistoryEvent;
use App\Support\Assets\AssetStatus;
use App\Support\Assets\IssuePriority;
use App\Support\Assets\IssueStatus;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reporting and tracking a problem with an asset — the trigger for an
 * AssetRepair (see AssetRepairService). The issue's own IssueStatus tracks
 * the granular OPEN -> ... -> RESOLVED/CLOSED workflow independently of the
 * asset's own coarser AssetStatus; only report() moves the asset's status,
 * and only when ISSUE_REPORTED is actually a legal move from where it is
 * (e.g. an asset already IN_STOCK has no "in use" state to flag as broken —
 * see AssetStatus::TRANSITIONS).
 */
final class AssetIssueService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetService $assets,
        private readonly AssetStatusTransitionService $transitions,
        private readonly AssetHistoryRecorder $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{reported_date?:string, priority?:string, title:string, description?:string|null}  $data
     */
    public function report(Asset $asset, array $data, User $actor): AssetIssue
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $tenant = $this->context->getOrFail();
            /** @var Asset $asset */
            $asset = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            $issue = AssetIssue::query()->create([
                'issue_number' => $this->numbers->nextIssueNumber($tenant),
                'asset_id' => $asset->getKey(),
                'reported_by' => $actor->getKey(),
                'reported_date' => $data['reported_date'] ?? now()->toDateString(),
                'priority' => $data['priority'] ?? IssuePriority::MEDIUM,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
            ]);

            if ($this->transitions->canTransition($asset->status, AssetStatus::ISSUE_REPORTED)) {
                $this->assets->changeStatus($asset, AssetStatus::ISSUE_REPORTED, $actor, "Issue reported: {$issue->title}");
            }

            $this->audit->log(
                AuditAction::ASSET_ISSUE_REPORTED,
                'Assets',
                $asset,
                new: ['issue_number' => $issue->issue_number, 'priority' => $issue->priority],
                description: "Reported issue {$issue->issue_number} on asset {$asset->asset_number}: {$issue->title}",
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::ISSUE_REPORTED, "Issue reported: {$issue->title} ({$issue->priority}).", actor: $actor);

            return $issue;
        });
    }

    /**
     * @param  array{status?:string, priority?:string, description?:string|null}  $data
     */
    public function update(AssetIssue $issue, array $data, User $actor): AssetIssue
    {
        return DB::transaction(function () use ($issue, $data, $actor) {
            /** @var AssetIssue $issue */
            $issue = AssetIssue::query()->whereKey($issue->getKey())->lockForUpdate()->firstOrFail();

            if ($issue->isClosed()) {
                throw ValidationException::withMessages(['status' => 'This issue is already resolved, closed, or cancelled.']);
            }

            $old = $issue->only(['status', 'priority', 'description']);
            $issue->update(array_intersect_key($data, array_flip(['status', 'priority', 'description'])));

            $this->audit->log(
                AuditAction::ASSET_ISSUE_UPDATED,
                'Assets',
                $issue,
                old: $old,
                new: $issue->only(['status', 'priority', 'description']),
                description: "Updated issue {$issue->issue_number}",
                actor: $actor,
            );

            return $issue;
        });
    }

    public function resolve(AssetIssue $issue, User $actor, ?string $notes = null): AssetIssue
    {
        return DB::transaction(function () use ($issue, $actor, $notes) {
            /** @var AssetIssue $issue */
            $issue = AssetIssue::query()->whereKey($issue->getKey())->lockForUpdate()->firstOrFail();

            if ($issue->isClosed()) {
                throw ValidationException::withMessages(['status' => 'This issue is already resolved, closed, or cancelled.']);
            }

            $issue->update([
                'status' => IssueStatus::RESOLVED,
                'resolved_at' => now(),
                'resolved_by' => $actor->getKey(),
            ]);

            $asset = $issue->asset;

            $this->audit->log(
                AuditAction::ASSET_ISSUE_RESOLVED,
                'Assets',
                $issue,
                description: "Resolved issue {$issue->issue_number}".($notes ? " — {$notes}" : ''),
                actor: $actor,
            );

            $this->history->log($asset, AssetHistoryEvent::ISSUE_REPORTED, "Issue {$issue->issue_number} resolved.".($notes ? " {$notes}" : ''), actor: $actor);

            return $issue;
        });
    }
}

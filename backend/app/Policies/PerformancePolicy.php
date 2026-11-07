<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EvaluationForm;
use App\Models\Kpi;
use App\Models\PerformanceCycle;
use App\Models\PerformanceGoal;
use App\Models\PerformanceReview;
use App\Models\PerformanceSetting;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * HRM > Performance Management's set-up: performance.view to read; Add /
 * Update / Delete per tab (performance-<tab>.<action>). Cycles, score weights
 * and reviews are all on the Performance review tab.
 */
class PerformancePolicy
{
    /** Record class => tab. */
    private const TABS = [
        Kpi::class => 'kpis',
        PerformanceGoal::class => 'goals',
        PerformanceCycle::class => 'reviews',
        PerformanceSetting::class => 'reviews',
        PerformanceReview::class => 'reviews',
        EvaluationForm::class => 'forms',
    ];

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_VIEW);
    }

    public function view(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::PERFORMANCE_VIEW);
    }

    /** $class names the tab: authorize('create', [Kpi::class, Kpi::class]). */
    public function create(User $user, ?string $class = null): bool
    {
        return $this->allows($user, $class, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, $record::class, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, $record::class, 'delete');
    }

    private function allows(User $user, ?string $class, string $action): bool
    {
        $tab = $class !== null ? (self::TABS[$class] ?? null) : null;

        return $tab !== null && $user->hasPermission("performance-{$tab}.{$action}");
    }
}

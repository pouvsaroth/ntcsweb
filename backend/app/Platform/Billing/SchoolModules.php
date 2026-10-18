<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Platform\Models\SchoolSubscription;

/**
 * What a school's plan allows (docs/backoffice/stage-5-billing.md): its console
 * modules (5c) and its student limit (5d).
 *
 * The plan read is the school's live subscription, else its most recent one
 * (an expired plan keeps its modules through the grace period; after that the
 * school is suspended — 5e). A school with no subscription at all gets every
 * module: every school is put on a plan when it is created or when billing
 * arrived, so "none" only happens in tests and is never a reason to lock a
 * school out.
 *
 * Bound `scoped`: one lookup per request or queued job.
 */
final class SchoolModules
{
    /** @var array<int, array{modules: list<string>|null, max_students: int|null}> */
    private array $memo = [];

    /** @return list<string>|null module codes; null = every module */
    public function enabled(?int $tenantId): ?array
    {
        if ($tenantId === null) {
            return null;
        }

        return $this->plan($tenantId)['modules'];
    }

    /** Active students the school's plan allows (5d); null = no limit, or no school. */
    public function maxStudents(?int $tenantId): ?int
    {
        return $tenantId === null ? null : $this->plan($tenantId)['max_students'];
    }

    /** @return array{modules: list<string>|null, max_students: int|null} */
    private function plan(int $tenantId): array
    {
        if (! array_key_exists($tenantId, $this->memo)) {
            $sub = SchoolSubscription::query()
                ->where('tenant_id', $tenantId)
                ->with('plan:id,modules,max_students')
                ->orderByRaw("status in ('trial', 'active') desc")
                ->latest('id')
                ->first(['id', 'plan_id', 'status']);

            $this->memo[$tenantId] = ['modules' => $sub?->plan?->modules, 'max_students' => $sub?->plan?->max_students];
        }

        return $this->memo[$tenantId];
    }

    public function has(?int $tenantId, string $module): bool
    {
        $enabled = $this->enabled($tenantId);

        return $enabled === null || in_array($module, $enabled, true);
    }

    /**
     * Drop the permissions whose group belongs to a module the plan lacks.
     *
     * @param  list<string>  $slugs
     * @return list<string>
     */
    public function filter(?int $tenantId, array $slugs): array
    {
        $enabled = $this->enabled($tenantId);
        if ($enabled === null) {
            return $slugs;
        }

        return array_values(array_filter($slugs, function (string $slug) use ($enabled) {
            $module = Modules::moduleOfGroup(strstr($slug, '.', true) ?: $slug);

            return $module === null || in_array($module, $enabled, true);
        }));
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}

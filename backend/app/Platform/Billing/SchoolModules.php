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
    /** @var array<int, array{modules: list<string>|null, menus: list<string>|null, max_students: int|null}> */
    private array $memo = [];

    private ?int $websiteSchoolId = null;

    /** @return list<string>|null module codes; null = every module */
    public function enabled(?int $tenantId): ?array
    {
        if ($tenantId === null || $this->isWebsiteSchool($tenantId)) {
            return null;
        }

        return $this->plan($tenantId)['modules'];
    }

    /** Active students the school's plan allows (5d); null = no limit, or no school. */
    public function maxStudents(?int $tenantId): ?int
    {
        return $tenantId === null ? null : $this->plan($tenantId)['max_students'];
    }

    /**
     * Every system the customer holds counts (docs/backoffice/stage-9-products.md):
     * per system its live subscription, else its latest one while that is
     * still in grace. What may be used is all of those together. When none
     * counts, the most recent subscription decides, as before systems.
     *
     * @return array{modules: list<string>|null, menus: list<string>|null, max_students: int|null}
     */
    private function plan(int $tenantId): array
    {
        if (! array_key_exists($tenantId, $this->memo)) {
            $subs = SchoolSubscription::query()
                ->where('tenant_id', $tenantId)
                ->with('plan:id,modules,menus,max_students')
                ->orderByRaw("status in ('trial', 'active') desc")
                ->latest('id')
                ->get(['id', 'plan_id', 'product_id', 'status', 'end_on']);

            if ($subs->isEmpty()) {
                return $this->memo[$tenantId] = ['modules' => null, 'menus' => null, 'max_students' => null];
            }

            $grace = \App\Platform\Models\BillingSettings::current()->grace_days;
            $today = SchoolSubscription::today();
            $counting = $subs->groupBy(fn ($s) => (int) $s->product_id)
                ->map(fn ($rows) => $rows->first()) // live first, else latest
                ->filter(fn ($s) => $s->isLive() || ($s->status === SchoolSubscription::EXPIRED && $s->end_on !== null && $s->end_on->copy()->addDays($grace)->gte($today)))
                ->values();
            if ($counting->isEmpty()) {
                $counting = collect([$subs->first()]);
            }

            $modules = [];
            $menus = [];
            $limits = [];
            foreach ($counting as $s) {
                $plan = $s->plan;
                $mods = $plan?->modules ?? [];
                $modules = array_merge($modules, $mods);
                // A plan's menus; null = every menu of its modules.
                $menus = array_merge($menus, $plan?->menus ?? array_merge([], ...array_map(fn ($m) => Modules::catalog()[$m]['groups'] ?? [], $mods)));
                $limits[] = $plan?->max_students;
            }

            $this->memo[$tenantId] = [
                'modules' => array_values(array_unique($modules)),
                'menus' => array_values(array_unique($menus)),
                // No limit on any plan = no limit; else the largest.
                'max_students' => in_array(null, $limits, true) ? null : (int) max($limits),
            ];
        }

        return $this->memo[$tenantId];
    }

    public function has(?int $tenantId, string $module): bool
    {
        $enabled = $this->enabled($tenantId);

        return $enabled === null || in_array($module, $enabled, true);
    }

    /**
     * Drop the permissions whose group belongs to a module the plan lacks, or
     * is a menu of an included module that the plan leaves out.
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
        $menus = $this->plan((int) $tenantId)['menus'];

        return array_values(array_filter($slugs, function (string $slug) use ($enabled, $menus) {
            // A single permission tied to a module (the dashboard's school tiles): the module decides.
            if (isset(Modules::slugModules()[$slug])) {
                return in_array(Modules::slugModules()[$slug], $enabled, true);
            }
            $group = strstr($slug, '.', true) ?: $slug;
            $module = Modules::moduleOfGroup($group);
            if ($module === null) {
                return true; // Core
            }

            return in_array($module, $enabled, true) && ($menus === null || in_array($group, $menus, true));
        }));
    }

    /**
     * NewTech's own school (`platform.website_school`, the one behind
     * newtechkh.com) is never limited by a plan: its website, and everything
     * else, stays on whatever plan it is on.
     */
    private function isWebsiteSchool(int $tenantId): bool
    {
        $slug = (string) config('platform.website_school');
        if ($slug === '') {
            return false;
        }
        return ($this->websiteSchoolId ??= (int) \App\Models\Tenant::query()->where('slug', $slug)->value('id')) === $tenantId;
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}

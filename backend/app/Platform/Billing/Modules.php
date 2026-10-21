<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Modules\Module;
use App\Modules\ModuleRegistry;

/**
 * The console's modules, as plans sell them (docs/backoffice/stage-5-billing.md, 5c).
 *
 * A module is a named list of console permission groups — the part before the
 * dot in `students.create`. A plan lists the module codes it includes; a
 * group in no module here is Core and always on.
 *
 * Adding a console feature: put its permission group in a module in the same
 * commit, or it is Core (every plan gets it).
 */
final class Modules
{
    /**
     * The modules as plans store and show them, from the module registry
     * (App\Modules, docs/architecture/multi-product.md).
     *
     * @return array<string, array{label: string, label_km: string, groups: list<string>}>
     */
    public static function catalog(): array
    {
        static $catalog = null;

        return $catalog ??= array_map(fn (Module $m) => ['label' => $m->label(), 'label_km' => $m->labelKm(), 'groups' => $m->groups()], ModuleRegistry::all());
    }

    /**
     * Each module's menus — one per permission group — as the plan form lists them.
     *
     * @return array<string, array{0: string, 1: string}> group => [English, Khmer]
     */
    public static function menuLabels(): array
    {
        static $labels = null;

        return $labels ??= array_merge(...array_values(array_map(fn (Module $m) => $m->menus(), ModuleRegistry::all())));
    }

    /**
     * Single permissions in a Core group that still belong to a module (the
     * dashboard's school tiles): permission slug => module.
     *
     * @return array<string, string>
     */
    public static function slugModules(): array
    {
        static $out = null;
        if ($out !== null) {
            return $out;
        }
        $out = [];
        foreach (ModuleRegistry::all() as $code => $m) {
            foreach ($m->dashboardPermissions() as $slug) {
                $out[$slug] = $code;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::catalog());
    }

    /** @return list<array{code: string, label: string, label_km: string, groups: list<string>, menus: list<array{code: string, label: string, label_km: string}>}> */
    public static function list(): array
    {
        $out = [];
        foreach (self::catalog() as $code => $module) {
            $out[] = ['code' => $code] + $module + ['menus' => array_map(fn (string $g) => [
                'code' => $g,
                'label' => self::menuLabels()[$g][0] ?? $g,
                'label_km' => self::menuLabels()[$g][1] ?? $g,
            ], $module['groups'])];
        }

        return $out;
    }

    /** Every menu (permission group) that belongs to a module. @return list<string> */
    public static function groups(): array
    {
        return array_merge(...array_values(array_map(fn (array $m) => $m['groups'], self::catalog())));
    }

    /**
     * A plan's menus as stored: only menus of its modules, and null when every
     * one of its modules has all its menus (so a plan saved before menus
     * existed, and one that simply keeps everything, read the same).
     *
     * @param  list<string>  $modules
     * @param  list<string>|null  $menus
     * @return list<string>|null
     */
    public static function normaliseMenus(array $modules, ?array $menus): ?array
    {
        if ($menus === null) {
            return null;
        }
        $inModules = array_merge([], ...array_map(fn (string $m) => self::catalog()[$m]['groups'] ?? [], $modules));
        $kept = array_values(array_intersect($inModules, $menus)); // catalog order

        return count($kept) === count($inModules) ? null : $kept;
    }

    /** The module a permission group belongs to, or null for Core. */
    public static function moduleOfGroup(string $group): ?string
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::catalog() as $code => $module) {
                foreach ($module['groups'] as $g) {
                    $index[$g] = $code;
                }
            }
        }

        return $index[$group] ?? null;
    }
}

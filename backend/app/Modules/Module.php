<?php

declare(strict_types=1);

namespace App\Modules;

use App\Models\Tenant;

/**
 * One module of the platform (docs/architecture/multi-product.md): what it
 * is called, its menus (one per console permission group), the dashboard
 * tiles that belong to it, and the starting data a customer gets with it.
 *
 * Systems (back office → Billing → Systems) are built from modules; plans
 * pick modules and menus. Adding a module: a class here, listed in
 * ModuleRegistry, its permissions in the same commit.
 */
abstract class Module
{
    /** Stable code, stored in plans: never change it. */
    abstract public function code(): string;

    abstract public function label(): string;

    abstract public function labelKm(): string;

    /**
     * Its menus: console permission groups (the part before the dot in
     * `students.view`), each with its English and Khmer name.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    abstract public function menus(): array;

    /**
     * Single permissions in a Core group that belong to this module — e.g.
     * the dashboard's student tiles belong to Academics.
     *
     * @return list<string>
     */
    public function dashboardPermissions(): array
    {
        return [];
    }

    /**
     * Its starting data in a customer's database, made when the customer is
     * set up or first gets this module. Must be safe to run again.
     */
    public function seed(Tenant $tenant): void {}

    /** @return list<string> */
    public function groups(): array
    {
        return array_keys($this->menus());
    }
}

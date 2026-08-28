<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Contracts;

use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * One strategy for working out which school a request belongs to.
 *
 * Implementations are listed in config/tenancy.php and tried in order. Adding a
 * new strategy (an API key, a signed token, a path prefix) means writing one of
 * these and adding it to that list — nothing else changes.
 */
interface TenantResolver
{
    public function resolve(Request $request): ?Tenant;
}

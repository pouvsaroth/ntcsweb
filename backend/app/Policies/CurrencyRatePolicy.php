<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CurrencyRate;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * CurrencyRate uses BelongsToTenant, so its global scope already makes a
 * cross-tenant rate unreachable before a policy method runs — these only
 * check the permission itself.
 */
class CurrencyRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CURRENCY_RATES_VIEW);
    }

    public function view(User $user, CurrencyRate $currencyRate): bool
    {
        return $user->hasPermission(Permissions::CURRENCY_RATES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::CURRENCY_RATES_CREATE);
    }

    public function update(User $user, CurrencyRate $currencyRate): bool
    {
        return $user->hasPermission(Permissions::CURRENCY_RATES_UPDATE);
    }

    public function delete(User $user, CurrencyRate $currencyRate): bool
    {
        return $user->hasPermission(Permissions::CURRENCY_RATES_DELETE);
    }
}

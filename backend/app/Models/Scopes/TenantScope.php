<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Exceptions\Tenancy\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the school in context.
 *
 * The important case is the third one: with no tenant and no explicit platform
 * mode the query throws instead of running unscoped. A forgotten middleware
 * therefore produces a 500 in development rather than quietly serving every
 * school's records to whoever asked.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isPlatform()) {
            return;
        }

        $tenantId = $context->id();

        if ($tenantId === null) {
            throw TenantNotResolvedException::make($model::class);
        }

        $builder->where($model->qualifyColumn($model->getTenantIdColumn()), $tenantId);
    }
}

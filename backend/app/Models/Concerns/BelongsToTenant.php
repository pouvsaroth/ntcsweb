<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\Tenancy\TenantMismatchException;
use App\Exceptions\Tenancy\TenantNotResolvedException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model.
 *
 * Gives three guarantees that hold without any cooperation from the controller:
 *
 *   READ    a global scope pins all queries to the school in context.
 *   CREATE  tenant_id is stamped from context, never from input.
 *   UPDATE  a row cannot be moved between schools, and a row belonging to
 *           another school cannot be saved even if it was somehow loaded.
 *
 * tenant_id must never appear in a model's $fillable. It is set here.
 *
 * @property int|null $tenant_id
 *
 * @method static Builder<static> forTenant(Tenant|int $tenant)
 * @method static Builder<static> acrossTenants()
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        // Deliberately hooked on "creating"/"updating", not the generic
        // "saving". Eloquent fires "saving" before "creating" on a brand new
        // model — the insert path is saving() then performInsert() then
        // creating() — so a "saving" check runs before tenant_id has been
        // stamped at all and would see it as an accidental mismatch on every
        // single create. "creating" and "updating" each run at the point
        // where the attribute is settled for that operation.
        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $column = $model->getTenantIdColumn();
            $provided = $model->getAttribute($column);

            if ($provided === null) {
                $tenantId = $context->id();

                // In platform mode with no explicit tenant_id given, the
                // caller has to say which school — we cannot guess.
                if ($tenantId === null) {
                    throw TenantNotResolvedException::forWrite($model::class);
                }

                $model->setAttribute($column, $tenantId);

                return;
            }

            // A tenant_id was supplied explicitly (only possible for a model
            // that does not guard the column, e.g. AuditLog). Platform mode
            // may target any school; otherwise it must be the ambient one.
            if (! $context->isPlatform() && $provided !== $context->id()) {
                throw TenantMismatchException::forModel($model::class, $provided, $context->id());
            }
        });

        static::updating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($context->isPlatform()) {
                return;
            }

            $column = $model->getTenantIdColumn();

            // Reassigning a record to another school is never a legitimate
            // in-tenant operation.
            if ($model->isDirty($column) || $model->getAttribute($column) !== $context->id()) {
                throw TenantMismatchException::forModel(
                    $model::class,
                    $model->getAttribute($column),
                    $context->id(),
                );
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, $this->getTenantIdColumn());
    }

    public function getTenantIdColumn(): string
    {
        return 'tenant_id';
    }

    /**
     * Query one specific school, ignoring the ambient context.
     *
     * Cross-tenant by definition — reserve for platform code and queued jobs.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForTenant(Builder $query, Tenant|int $tenant): void
    {
        $query->withoutGlobalScope(TenantScope::class)
            ->where(
                $this->qualifyColumn($this->getTenantIdColumn()),
                $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            );
    }

    /**
     * Drop tenant scoping entirely.
     *
     * Every call site is a deliberate cross-tenant read and should be treated
     * as security-sensitive during review.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAcrossTenants(Builder $query): void
    {
        $query->withoutGlobalScope(TenantScope::class);
    }
}

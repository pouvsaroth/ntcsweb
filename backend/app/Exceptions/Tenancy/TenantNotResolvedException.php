<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;

/**
 * Thrown when tenant-scoped work is attempted with no school in context.
 *
 * This is the system failing closed. It is a bug (or an attack), never a
 * routine condition, so it is intentionally loud.
 */
class TenantNotResolvedException extends RuntimeException
{
    public static function make(?string $model = null): self
    {
        $subject = $model === null
            ? 'A tenant-scoped query was executed'
            : sprintf('A query on [%s] was executed', $model);

        return new self(
            $subject.' with no tenant in context. '
            .'Resolve a tenant with the "tenant" middleware, enter one explicitly with '
            .'TenantContext::runFor(), or — only for deliberate cross-tenant work — '
            .'TenantContext::withoutTenancy().'
        );
    }

    public static function forWrite(string $model): self
    {
        return new self(
            sprintf(
                'Cannot persist [%s]: it is tenant-owned but no tenant is in context. '
                .'Wrap the write in TenantContext::runFor($tenant, ...) or set the tenant '
                .'explicitly while in platform mode.',
                $model
            )
        );
    }
}

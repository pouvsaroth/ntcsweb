<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Platform\Models\SchoolPlacement;
use Stancl\Tenancy\DatabaseConfig;

/**
 * stancl/tenancy's per-school database config, made placement-aware: every
 * place that opens a school's database (requests, queue jobs, seeders, the
 * deploy's tenants:provision-all-databases) asks this, so they all follow the
 * school's placement without being changed themselves.
 *
 *   shared     the shared database; row-level security keeps the schools apart
 *   dedicated  <name_prefix><slug> on the main server
 *   separate   the pasted database, host, port, login, password and SSL mode.
 *              No SET ROLE there: that login is not the platform's superuser,
 *              and FORCEd row-level security binds it even if it owns the tables.
 */
class SchoolDatabaseConfig extends DatabaseConfig
{
    private ?SchoolPlacement $placement = null;

    public function placement(): SchoolPlacement
    {
        return $this->placement ??= SchoolPlacement::of($this->tenant);
    }

    public function getName(): ?string
    {
        return $this->placement()->database;
    }

    public function connection(): array
    {
        $config = parent::connection();
        $placement = $this->placement();

        if (! $placement->isSeparate()) {
            return $config;
        }

        return array_merge($config, [
            'url' => null,
            'host' => $placement->host,
            'port' => $placement->port,
            'username' => $placement->username,
            'password' => $placement->password,
            'sslmode' => $placement->sslmode ?: 'prefer',
            'app_role' => '',
        ]);
    }

    /** The platform creates the database itself (shared, dedicated) — never on a separate server. */
    public function isCreatedByPlatform(): bool
    {
        return ! $this->placement()->isSeparate();
    }
}

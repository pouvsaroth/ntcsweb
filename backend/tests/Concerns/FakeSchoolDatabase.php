<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Platform\Services\SchoolDatabase;

/**
 * Stands in for SchoolDatabase so the suite never creates real databases on
 * the Postgres server. Everything after the database step — seeding, the
 * health check, activation, the invite — runs for real against the test
 * database (which, in tests, is also what the `tenant` connection points at).
 */
class FakeSchoolDatabase extends SchoolDatabase
{
    /** @var list<string> databases that "exist" on the server */
    public array $existing = [];

    /** @var list<string> slugs createAndMigrate() was called for */
    public array $created = [];

    /** Throw this from createAndMigrate() the next N times. */
    public int $failTimes = 0;

    public function __construct() {}

    public function exists(string $name): bool
    {
        return in_array($name, $this->existing, true);
    }

    public function createAndMigrate(Tenant $tenant): void
    {
        if ($this->failTimes > 0) {
            $this->failTimes--;

            throw new \RuntimeException('could not connect to server: Connection refused');
        }

        $this->created[] = $tenant->slug;
        $this->existing[] = $this->nameFor($tenant->slug);
    }

    public function within(Tenant $tenant, callable $callback): mixed
    {
        return $callback();
    }
}

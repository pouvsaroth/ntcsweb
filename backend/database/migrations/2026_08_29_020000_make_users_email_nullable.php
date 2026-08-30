<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phone, not email, is now the required identifier for auto-provisioned
 * Student/Staff accounts (the school may not have an email on file, but
 * always has a phone number). `AuthService` already accepts either as a
 * login, and `UNIQUE(tenant_id, email)` already tolerates multiple NULLs
 * under Postgres, so the only blocker was the NOT NULL constraint itself.
 *
 * Raw SQL, not `$table->string('email')->nullable()->change()`: this project
 * has no doctrine/dbal dependency (needed for Blueprint::change()), and the
 * codebase already reaches for raw DB::statement() for Postgres-specific DDL
 * — see the partial unique indexes in upgrade_users_table_for_tenancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
    }
};

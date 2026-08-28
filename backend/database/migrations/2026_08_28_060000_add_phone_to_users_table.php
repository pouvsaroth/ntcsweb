<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a user log in with a phone number instead of an email, exactly the
 * same tenant-scoped uniqueness pattern as `email` and for the identical
 * reason: two schools may each have a person holding the same phone number.
 *
 * `UNIQUE(tenant_id, phone)` alone doesn't stop two *platform* accounts
 * (tenant_id IS NULL) from sharing a phone number — Postgres treats every
 * NULL as distinct, so a composite index with a NULL column never fires for
 * those rows. The extra partial index closes that gap, same as
 * `users_email_platform_unique` already does for email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique(['tenant_id', 'phone']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX users_phone_platform_unique ON users (phone) WHERE tenant_id IS NULL AND phone IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_phone_platform_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'phone']);
            $table->dropColumn('phone');
        });
    }
};

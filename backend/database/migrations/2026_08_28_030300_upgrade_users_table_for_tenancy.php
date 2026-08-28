<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned table (users.tenant_id NULL == platform super admin).
 *
 * Two things happen here:
 *
 * 1. Email uniqueness moves from platform-wide to per-tenant. The same person
 *    can hold an account at NewTech and at ABC School. Platform super admins
 *    (tenant_id IS NULL) still need a globally unique email, which a plain
 *    UNIQUE(tenant_id, email) cannot enforce because Postgres treats every NULL
 *    as distinct — hence the partial unique index.
 *
 * 2. password_reset_tokens is re-keyed on (tenant_id, email) for the same
 *    reason; its old PRIMARY KEY(email) would collide across schools.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('password');

            $table->string('locale', 10)->nullable()->after('status');

            $table->timestamp('last_login_at')->nullable()->after('locale');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');

            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');

            $table->unique(['tenant_id', 'email']);

            // Admin list screens: "active users of this school, newest first".
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        // Platform super admins share the NULL tenant, so UNIQUE(tenant_id, email)
        // does not constrain them. A partial index restores global uniqueness there.
        DB::statement(
            'CREATE UNIQUE INDEX users_email_platform_unique ON users (email) WHERE tenant_id IS NULL'
        );

        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('email')
                ->constrained('tenants')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE password_reset_tokens DROP CONSTRAINT password_reset_tokens_pkey');

        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->unique(['tenant_id', 'email']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX password_reset_tokens_email_platform_unique
             ON password_reset_tokens (email) WHERE tenant_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS password_reset_tokens_email_platform_unique');

        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropConstrainedForeignId('tenant_id');
        });

        DB::statement('ALTER TABLE password_reset_tokens ADD PRIMARY KEY (email)');

        DB::statement('DROP INDEX IF EXISTS users_email_platform_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropUnique(['tenant_id', 'email']);
            $table->unique('email');

            $table->dropSoftDeletes();
            $table->dropColumn(['status', 'locale', 'last_login_at', 'last_login_ip']);
        });
    }
};

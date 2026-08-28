<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide table.
 *
 * Rounds out `tenants` with the columns the platform needs and removes the
 * single-hostname `domain` column, now superseded by `tenant_domains`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Short human code used on printed documents / student IDs, e.g. "NTCS".
            $table->string('code', 32)->nullable()->unique()->after('slug');

            $table->string('locale', 10)->default('en')->after('timezone');

            // Free-form per-school configuration (branding, public site copy,
            // feature flags). Read through TenantSettings, never ad-hoc.
            $table->jsonb('settings')->nullable()->after('status');

            $table->timestamp('trial_ends_at')->nullable()->after('settings');

            $table->softDeletes();
        });

        Schema::table('tenants', function (Blueprint $table) {
            // Superseded by tenant_domains, which allows many hostnames per tenant.
            $table->dropUnique('tenants_domain_unique');
            $table->dropColumn('domain');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('domain')->nullable()->unique()->after('slug');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique('tenants_code_unique');
            $table->dropSoftDeletes();
            $table->dropColumn(['code', 'locale', 'settings', 'trial_ends_at']);
        });
    }
};

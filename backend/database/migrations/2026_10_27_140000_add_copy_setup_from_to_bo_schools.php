<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A new school can start as a copy of another school's setup (programs,
 * courses, classes, lookups, chart of accounts, HRM setup… never people or
 * logins) — see App\Platform\Services\SchoolSetupCopier. Kept on the school
 * so a retried provisioning copies again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_schools', function (Blueprint $table) {
            $table->foreignId('copy_setup_from_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bo_schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('copy_setup_from_tenant_id');
        });
    }
};

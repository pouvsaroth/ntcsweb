<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2 of the back office — schools. See docs/backoffice/stage-2-schools.md.
 *
 * The school itself stays the `tenants` row the whole app already reads; what
 * only the back office cares about (who owns it, who created it, why its
 * last setup failed) lives beside it in bo_schools, one row per school,
 * created lazily for schools that predate the back office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_schools', function (Blueprint $table) {
            $table->foreignId('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('bo_staff')->nullOnDelete();
            // Why the last provisioning attempt failed — shown beside Retry.
            $table->text('status_detail')->nullable();
            $table->unsignedInteger('provisioning_attempts')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('invite_sent_at')->nullable();
            $table->timestamps();
        });

        // One row per step per attempt. Append-only.
        Schema::create('bo_school_provisioning_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('step', 50);
            $table->string('status', 20); // ok | failed
            $table->text('message')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'attempt']);
        });

        Schema::create('bo_school_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            // Null when the platform itself moved it (the provisioning job).
            $table->foreignId('staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });

        // A custom domain added from the back office carries a token until its
        // DNS TXT record is found. Rows that predate this have no token and
        // keep resolving exactly as before.
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->string('verification_token', 64)->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropColumn('verification_token');
        });
        Schema::dropIfExists('bo_school_status_history');
        Schema::dropIfExists('bo_school_provisioning_logs');
        Schema::dropIfExists('bo_schools');
    }
};

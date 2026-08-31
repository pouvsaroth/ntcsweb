<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. One row per uploaded CSV — tracks the background job's
 * progress and results so the admin UI can poll it rather than the request
 * blocking until a potentially huge file finishes processing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_imports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('original_filename');
            // Storage-relative path, same convention as HomeSlide/Student
            // photos — kept after processing for troubleshooting, not
            // auto-deleted.
            $table->string('file_path');

            $table->string('status', 20)->default('pending'); // pending | processing | completed | failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            // [{row: 4, message: "..."}], capped at 100 entries by the job —
            // see ProcessStudentImport::MAX_RECORDED_ERRORS.
            $table->jsonb('errors')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_imports');
    }
};

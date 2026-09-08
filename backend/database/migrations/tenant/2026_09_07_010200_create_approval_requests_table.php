<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One user's submission against a FormTemplate — the generic counterpart to
 * LeaveRequest (which keeps its own dedicated table/fields for its
 * date-range+attachments shape; an ApprovalRequest instead carries a
 * free-text `details` body, same as every other form in the eApprovals
 * catalog until a template needs richer structured fields). `reference` is
 * derived from the id at read time (see ApprovalRequestResource), not
 * stored. Named ApprovalRequest, not FormRequest, to avoid colliding with
 * Laravel's own Illuminate\Foundation\Http\FormRequest.
 *
 * Lives in the school's own database, same as FormTemplate — no
 * `tenant_id` column. `requested_by`/`decided_by` stay plain bigints with
 * no DB-level foreign key: `users` hasn't moved to a per-tenant database
 * yet, and a cross-database foreign key isn't possible in Postgres
 * regardless — see ApprovalRequest's own relations, which still resolve
 * correctly as ordinary separate queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $table->unsignedBigInteger('requested_by');

            $table->string('subject');
            $table->text('details')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected

            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('requested_by');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};

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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            $table->string('subject');
            $table->text('details')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected

            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'requested_by']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};

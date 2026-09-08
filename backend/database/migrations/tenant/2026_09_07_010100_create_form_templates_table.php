<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A requestable form type in the eApprovals "Forms" catalog — e.g. "Office
 * Transfer Request", code "TT-OTR-FM-001". Any authenticated user can browse
 * these (see FormTemplateController::index, deliberately unguarded like
 * LeaveRequest submission) to submit a FormRequest against one; only
 * form-templates.manage can create/update/delete the catalog itself.
 *
 * Lives in the school's own database, same as FormCategory — no
 * `tenant_id` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_category_id')->constrained('form_categories')->cascadeOnDelete();

            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique('code');
            $table->index(['form_category_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_templates');
    }
};

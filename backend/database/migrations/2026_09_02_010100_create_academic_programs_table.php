<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The academic curriculum area a school teaches — English, Chinese,
 * Computer, and anything else an administrator adds later (Korean,
 * Accounting, Programming, ...).
 *
 * Deliberately NOT named `Program`/`programs` — that model already exists
 * in this app as the public marketing catalog shown on the school's
 * website (title/subtitle/fee/image, its own controllers/policy/permission
 * slugs, a live FK from EnrollmentInquiry). Naming this `AcademicProgram`
 * avoids silently changing the meaning of that already-shipped, seeded,
 * role-assigned `programs.*` permission group. The two are unrelated and
 * both stay in the schema side by side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_programs');
    }
};

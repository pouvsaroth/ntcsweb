<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned, configurable catalog of dropdown/lookup categories (GENDER,
 * GUARDIAN_TYPE, BOOK_TYPE, PAYMENT_METHOD, ...) -- mirrors the StudyMode/
 * AcademicProgram precedent: a school can add its own categories/values on
 * top of the seeded defaults, not a fixed platform-wide enum.
 *
 * `code` is the stable system identifier application logic branches on
 * (LOOKUP_CATEGORY->code === 'GENDER'); `name` is display-only admin text
 * and must never be used as an identifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookup_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookup_categories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A department/category grouping in the eApprovals "Forms" catalog (e.g.
 * "General", "Finance", "IT") — see FormTemplate, which each belongs to one
 * of these. Seeded with a single "General" category per existing tenant by
 * the migration right after this one; a school adds more through the admin
 * Form Categories screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_categories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One selectable option within a Lookup Category (e.g. GENDER -> MALE).
 * `code` is stable and unique within its category+tenant; display text
 * lives entirely in lookup_value_translations, never here -- see that
 * migration's docblock for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookup_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lookup_category_id')->constrained('lookup_categories')->cascadeOnDelete();
            $table->string('code', 50);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'lookup_category_id', 'code']);
            $table->index(['tenant_id', 'lookup_category_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookup_values');
    }
};

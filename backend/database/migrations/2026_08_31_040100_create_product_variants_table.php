<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-product variants (a T-Shirt's S/M/L/XL/XXL) — nullable on the
 * invoice item, never required. A product that doesn't need sizing (a
 * course fee, a book) simply has no rows here.
 *
 * `price_override` is nullable: most variants share the parent product's
 * price and only the ones that genuinely cost more/less set one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name', 64);
            $table->decimal('price_override', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};

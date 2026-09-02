<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The priced, purchasable registration item a student actually pays for —
 * e.g. "MS Word 2024" at $24 — which may bundle several Books (see
 * course_package_book) taught together as one class curriculum. This is
 * the key distinction the whole module exists for: a registration/package
 * is NOT the same thing as an individual subject.
 *
 * `product_id` links to the existing billable-catalog Product row this
 * package auto-owns (see CoursePackageService) — every InvoiceItem still
 * points at a Product, so the existing RevenueAccountResolver/
 * FinancialTransactionService accounting machinery works completely
 * unmodified. `price` here is always the CURRENT catalog price; an
 * already-issued InvoiceItem snapshots its own unit_price at creation time
 * and never re-reads this column, so changing it here never rewrites
 * history — see InvoiceService::addItem().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->foreignId('academic_program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('duration', 50)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'academic_program_id']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_packages');
    }
};

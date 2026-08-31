<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One billed line — a course fee, a book, a T-shirt in a given size, or
 * anything else in `products`. This is the one design choice that makes the
 * whole billing system generic: there is no `student_course_payment`,
 * `student_book_payment`, `student_tshirt_payment`; every kind of charge is
 * just a row here pointing at a Product (and optionally a ProductVariant).
 *
 * `unit_price` and `discount` are a snapshot taken at invoicing time, copied
 * from Product::$price (or ProductVariant::$price_override) at the moment
 * the item is created — never a live read. A later change to the product's
 * catalog price must never alter what an already-issued invoice shows or
 * owes; see InvoiceService.
 *
 * `reference_type`/`reference_id` optionally point at the business record
 * that produced this charge (e.g. an Enrollment, for a course-fee item) —
 * polymorphic, mirroring audit_logs.auditable_type/id, so a future product
 * type can supply its own reference without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('total', 10, 2);

            $table->string('reference_type', 191)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};

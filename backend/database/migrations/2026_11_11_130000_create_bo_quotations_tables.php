<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing > Quotations: a price offer to a customer (or a prospect not signed
 * up yet) for picked console menus / submenus / tabs. Lines keep their own
 * copy of names and prices, so changing the catalog never changes a quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_quotations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('customer_name', 200);
            $table->string('contact_name', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('currency', 3); // USD | KHR
            $table->date('issue_date');
            $table->date('valid_until')->nullable();
            $table->string('discount_type', 10)->default('amount'); // amount | percent
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('discount_amount', 16, 2)->default(0);
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft'); // draft | sent | accepted | rejected
            $table->foreignId('staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'issue_date']);
        });

        Schema::create('bo_quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('bo_quotations')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('bo_quotation_items')->nullOnDelete();
            $table->string('level', 10)->nullable(); // menu | submenu | tab | null (typed by hand)
            $table->string('code', 30)->nullable();
            $table->string('name', 200);
            $table->string('name_km', 200)->nullable();
            // Where it sits, e.g. "Academic › Programs".
            $table->string('parent_label', 300)->nullable();
            $table->string('parent_label_km', 300)->nullable();
            $table->string('description', 1000)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('amount', 16, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_quotation_lines');
        Schema::dropIfExists('bo_quotations');
    }
};

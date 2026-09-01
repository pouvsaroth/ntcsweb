<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. One physical item the school owns — see Asset's own
 * docblock for why `specs` is a flexible jsonb blob for category-specific
 * fields (CPU/RAM/OS/...) instead of a per-category table, while
 * `hostname`/`mac_address`/`ip_address` are real indexed columns since
 * those three are the ones spec explicitly wants searchable.
 *
 * `serial_number`/`asset_tag` are nullable and still unique per tenant —
 * Postgres treats multiple NULLs as distinct under a unique index, so this
 * needs no special partial-index handling.
 *
 * Disposal fields live here directly (not a separate table) — an asset is
 * disposed at most once, a terminal event, so a join buys nothing. Retiring/
 * disposing never deletes the row — see the soft-deletes note below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('asset_number', 32);
            $table->foreignId('category_id')->constrained('asset_categories')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('asset_tag', 64)->nullable();

            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->decimal('current_value', 15, 2)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            $table->date('warranty_start_date')->nullable();
            $table->date('warranty_end_date')->nullable();
            $table->string('warranty_provider')->nullable();
            $table->string('warranty_number', 100)->nullable();

            $table->foreignId('location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->string('status', 20)->default('IN_STOCK');
            $table->string('condition', 20)->default('NEW');

            // Searchable computer-specific fields — see the migration's own
            // docblock for why these three (of many possible spec fields)
            // are real columns instead of living in `specs`.
            $table->string('hostname', 100)->nullable();
            $table->string('mac_address', 32)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->jsonb('specs')->nullable();

            $table->date('disposal_date')->nullable();
            $table->text('disposal_reason')->nullable();
            $table->string('disposal_method', 30)->nullable();
            $table->decimal('disposal_value', 15, 2)->nullable();
            $table->foreignId('disposal_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disposed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            // A safety net for a genuine mis-entry, not the normal lifecycle
            // end — that's RETIRED/DISPOSED status, which keeps the row (and
            // every history/repair/assignment record pointing at it) intact.
            $table->softDeletes();

            $table->unique(['tenant_id', 'asset_number']);
            $table->unique(['tenant_id', 'serial_number']);
            $table->unique(['tenant_id', 'asset_tag']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'location_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'hostname']);
            $table->index(['tenant_id', 'mac_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};

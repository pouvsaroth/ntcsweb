<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. A repair job for one asset, optionally tracing back to the
 * Issue that triggered it. `total_cost` is always server-computed from the
 * individual cost fields — see AssetRepairService, never trusted from a
 * request. `expense_id` links to the Accounting Expense created (in
 * PENDING_APPROVAL, never auto-paid) once the repair completes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_repairs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('repair_number', 32);
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('issue_id')->nullable()->constrained('asset_issues')->nullOnDelete();
            $table->foreignId('repair_shop_id')->nullable()->constrained('repair_shops')->nullOnDelete();

            $table->date('sent_date');
            $table->date('expected_return_date')->nullable();
            $table->date('actual_return_date')->nullable();

            $table->text('problem_description')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('repair_description')->nullable();
            $table->string('status', 20)->default('PENDING');

            $table->decimal('diagnosis_cost', 15, 2)->default(0);
            $table->decimal('parts_cost', 15, 2)->default(0);
            $table->decimal('labor_cost', 15, 2)->default(0);
            $table->decimal('transport_cost', 15, 2)->default(0);
            $table->decimal('other_cost', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);

            $table->unsignedInteger('warranty_days')->nullable();
            $table->string('condition_after_repair', 20)->nullable();

            $table->string('decision', 20)->nullable(); // REPAIR | REPLACE | RETIRE | DISPOSE
            $table->foreignId('decision_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('decision_date')->nullable();
            $table->text('decision_reason')->nullable();

            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'repair_number']);
            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_repairs');
    }
};

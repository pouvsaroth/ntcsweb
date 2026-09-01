<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. A scheduled or completed maintenance task. `next_maintenance_date`
 * is computed and stored once when a recurring maintenance completes
 * (`completed_date + recurrence_interval_months`) — read passively, no
 * background job schedules anything automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_maintenances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('maintenance_number', 32);
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            $table->string('maintenance_type');
            $table->date('scheduled_date');
            $table->date('completed_date')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('repair_shop_id')->nullable()->constrained('repair_shops')->nullOnDelete();
            $table->decimal('cost', 15, 2)->nullable();
            $table->string('status', 20)->default('SCHEDULED');

            $table->unsignedInteger('recurrence_interval_months')->nullable();
            $table->date('next_maintenance_date')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'maintenance_number']);
            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'status', 'scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenances');
    }
};

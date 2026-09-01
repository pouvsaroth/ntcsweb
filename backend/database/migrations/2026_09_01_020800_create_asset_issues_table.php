<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tenant-owned. A reported problem with an asset — the trigger for a Repair; see AssetIssueService. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_issues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('issue_number', 32);
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('reported_date');
            $table->string('priority', 20)->default('MEDIUM');
            $table->string('status', 20)->default('OPEN');
            $table->string('title');
            $table->text('description')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'issue_number']);
            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_issues');
    }
};

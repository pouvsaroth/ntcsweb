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

            $table->string('issue_number', 32);
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            // reported_by/resolved_by: no DB-level foreign key — `users`
            // hasn't moved to a per-tenant database yet, and a
            // cross-database foreign key isn't possible in Postgres
            // regardless.
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->date('reported_date');
            $table->string('priority', 20)->default('MEDIUM');
            $table->string('status', 20)->default('OPEN');
            $table->string('title');
            $table->text('description')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();

            $table->timestamps();

            $table->unique('issue_number');
            $table->index('asset_id');
            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_issues');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. A closed accounting period blocks new financial postings
 * dated inside it — see AccountingPeriodGuard. A period only ever gets a row
 * here once closed; an open period simply has no row (no need to
 * pre-create one for every month).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();

            $table->char('period', 7); // 'YYYY-MM'
            $table->timestamp('closed_at');
            // No DB-level foreign key: `users` hasn't moved to a per-tenant
            // database yet, and a cross-database foreign key isn't possible
            // in Postgres regardless.
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->timestamps();

            $table->unique('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. The Chart of Accounts — a configurable tree (via `parent_id`)
 * of ASSET/LIABILITY/EQUITY/REVENUE/EXPENSE accounts. Nothing in the
 * accounting module hard-codes "Salary"/"Rent"/"Course Fees" as strings; it
 * always resolves an actual Account row. See ChartOfAccountsSeeder for the
 * default tree every tenant starts with (fully editable afterward).
 *
 * `is_bank_or_cash` distinguishes an ASSET account that is an actual money
 * location (Cash, Bank - ABA) from other assets (e.g. Equipment, if ever
 * added) — cash-flow and balance reporting sum only these.
 *
 * No soft-deletes: an account already referenced by a transaction is never
 * deleted, only deactivated (`is_active`) — see AccountService::deactivate().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 20);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_bank_or_cash')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};

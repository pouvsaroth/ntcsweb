<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Accounting > Expense for: who an expense is for (e.g.
 * Newtech, Flexi, Family), so spending can be split and totalled by it.
 * Required on new expenses (StoreExpenseRequest); older ones stay blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_entities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('expense_entity_id')->nullable()->after('account_id')->constrained('expense_entities')->restrictOnDelete();
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'expense_entities');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_entity_id');
        });
        Schema::dropIfExists('expense_entities');
    }
};

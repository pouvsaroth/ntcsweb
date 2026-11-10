<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Fund > Funds: separate pots of the school's money, each with
 * a type (Base Data FUND_TYPE) and one currency. Money in / money out are
 * entries posted to the ledger (Cash/Bank <-> Funds).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->string('fund_no', 20)->nullable()->unique(); // F00001, from the id
            $table->string('name');
            $table->string('fund_type', 50); // a FUND_TYPE Base Data code
            $table->string('currency', 3); // USD | KHR
            $table->date('opened_on');
            $table->decimal('target_amount', 15, 2)->nullable(); // optional goal
            $table->string('status', 10)->default('active'); // active | closed
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('fund_type');
        });

        Schema::create('fund_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_id')->constrained('funds')->cascadeOnDelete();
            $table->string('type', 10); // in | out
            $table->decimal('amount', 15, 2);
            $table->date('transacted_on');
            $table->string('party')->nullable(); // money from (in) / paid to (out)
            $table->string('remark', 500)->nullable();
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedBigInteger('transaction_id')->nullable(); // the ledger entry
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['fund_id', 'transacted_on']);
            $table->index('transacted_on');
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'funds');
        SchoolTables::secure($db, 'fund_transactions');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_transactions');
        Schema::dropIfExists('funds');
    }
};

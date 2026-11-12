<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Accounting > Expenses > Support: people who help with a cost
 * (e.g. visitors at a hospital) — money (amount + currency) or food (what
 * they brought, an estimated amount optional) — kept against an Expense for,
 * so its expenses can be netted against the money received. A list only;
 * nothing is posted to the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_supports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 50)->nullable();
            $table->string('relationship', 30)->nullable(); // relative | friend | neighbour | colleague | other
            $table->date('support_date');
            $table->string('kind', 10); // money | food
            $table->decimal('amount', 14, 2)->nullable(); // required for money; an estimate for food
            $table->string('currency', 3)->nullable();
            $table->string('items', 500)->nullable(); // food: what they brought
            $table->foreignId('expense_entity_id')->constrained('expense_entities')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['expense_entity_id', 'support_date']);
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'expense_supports');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_supports');
    }
};

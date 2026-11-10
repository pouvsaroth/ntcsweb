<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Loan > Customers: the people who keep savings with the
 * school owner and borrow from them (Savings and Owe). Not students or
 * staff — anyone. `code` (C00001) is set from the id when a customer is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique();
            $table->string('name');
            $table->string('gender', 10)->nullable(); // male | female
            $table->string('phone', 32)->nullable();
            $table->string('id_card_no', 50)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'loan_customers');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_customers');
    }
};

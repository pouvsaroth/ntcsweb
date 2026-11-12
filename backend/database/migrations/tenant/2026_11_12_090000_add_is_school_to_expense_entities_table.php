<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense for > "This is the school": the dashboard's income and expense
 * tiles count only the school's expenses (ticked ones, plus none picked).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_entities', function (Blueprint $table) {
            $table->boolean('is_school')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('expense_entities', function (Blueprint $table) {
            $table->dropColumn('is_school');
        });
    }
};

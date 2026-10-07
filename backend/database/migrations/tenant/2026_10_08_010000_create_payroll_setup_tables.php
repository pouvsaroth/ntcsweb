<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Payroll's set-up (stage 1): what a staff member is paid, before any
 * payroll is run.
 *
 * - payroll_components: the named pay items — an allowance, a bonus or a
 *   deduction — each a fixed amount or a percentage of basic salary, and
 *   whether it counts toward tax / social security.
 * - salary_structures (+ items): reusable packages of components with
 *   amounts, in one currency, given to many staff at once.
 * - staff_salaries: a staff member's basic salary over time — one row per
 *   change, effective from a date (the latest one on or before a day is the
 *   salary that day). Per-staff currency (USD or KHR).
 * - staff_pay_components: a component given to one staff member — recurring
 *   between two dates, or once (paid in the payroll covering its date).
 *
 * Every amount is a monthly one; a 15-day payroll pays half of the
 * recurring ones (see the payroll calculation, stage 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_components', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // allowance | bonus | deduction
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('calculation', 20)->default('fixed'); // fixed | percent_of_basic
            // Allowance/bonus: part of taxable / NSSF-able pay. Deduction:
            // taken off before tax / NSSF are worked out.
            $table->boolean('affects_tax')->default(true);
            $table->boolean('affects_social_security')->default(false);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('kind');
        });

        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('currency', 3);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('salary_structure_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained('salary_structures')->cascadeOnDelete();
            $table->foreignId('payroll_component_id')->constrained('payroll_components')->restrictOnDelete();
            // A fixed amount in the structure's currency, or a percentage.
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['salary_structure_id', 'payroll_component_id']);
        });

        Schema::create('staff_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->decimal('basic_salary', 15, 2);
            $table->string('currency', 3);
            $table->foreignId('salary_structure_id')->nullable()->constrained('salary_structures')->nullOnDelete();
            $table->date('effective_from');
            $table->string('payment_method', 20)->default('bank'); // bank | cash
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number', 64)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'effective_from']);
        });

        Schema::create('staff_pay_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('payroll_component_id')->constrained('payroll_components')->restrictOnDelete();
            // A fixed amount in the staff member's salary currency, or a percentage.
            $table->decimal('amount', 15, 2);
            $table->string('recurrence', 20)->default('recurring'); // recurring | once
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_pay_components');
        Schema::dropIfExists('staff_salaries');
        Schema::dropIfExists('salary_structure_items');
        Schema::dropIfExists('salary_structures');
        Schema::dropIfExists('payroll_components');
    }
};

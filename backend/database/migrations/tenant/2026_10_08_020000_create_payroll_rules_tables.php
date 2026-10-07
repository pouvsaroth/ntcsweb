<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Payroll's rules (stage 2) — how overtime, attendance, tax, social
 * security and loans turn into amounts on a payslip.
 *
 * - payroll_settings: one row. Working days a month and hours a day (the
 *   daily/hourly rate of a monthly salary), overtime multipliers, what
 *   attendance deducts, and Tax on Salary's dependant allowances and
 *   non-resident rate.
 * - tax_brackets: monthly Tax on Salary in riel, progressive.
 * - social_security_schemes: NSSF contributions — % of the wage, clamped
 *   between a floor and a ceiling (riel), for staff and the school.
 * - staff_payroll_profiles: per staff — tax resident, dependants, NSSF.
 * - staff_loans (+ repayments): loans and salary advances, paid back by a
 *   fixed installment each payroll or in cash.
 *
 * The defaults are Cambodia's (Tax on Salary brackets, NSSF occupational
 * risk / health care / pension, Labour Law overtime) — every school can
 * change them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('working_days_per_month', 4, 1)->default(26);
            $table->decimal('hours_per_day', 4, 2)->default(8);
            // Overtime pay = hours × hourly rate × multiplier.
            $table->decimal('overtime_normal_rate', 4, 2)->default(1.5);
            $table->decimal('overtime_rest_day_rate', 4, 2)->default(2);
            $table->decimal('overtime_holiday_rate', 4, 2)->default(2);
            // Attendance deduction.
            $table->boolean('deduct_absence')->default(true);
            $table->boolean('deduct_unpaid_leave')->default(true);
            $table->string('late_deduction_mode', 20)->default('per_minute'); // none | per_minute | per_occurrence
            $table->unsignedSmallInteger('late_grace_minutes')->default(0);
            $table->decimal('late_amount_usd', 15, 2)->default(0);
            $table->decimal('late_amount_khr', 15, 2)->default(0);
            $table->boolean('deduct_early_leave')->default(true);
            // Tax on Salary (riel a month).
            $table->decimal('tax_spouse_allowance', 15, 2)->default(150000);
            $table->decimal('tax_child_allowance', 15, 2)->default(150000);
            $table->decimal('tax_non_resident_rate', 5, 2)->default(20);
            $table->timestamps();
        });

        Schema::create('tax_brackets', function (Blueprint $table) {
            $table->id();
            // Riel a month: the part of taxable pay above `min_amount` (up to
            // `max_amount`, open-ended when null) is taxed at `rate` %.
            $table->decimal('min_amount', 15, 2);
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->decimal('rate', 5, 2);
            $table->timestamps();
        });

        Schema::create('social_security_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->decimal('employee_rate', 5, 2)->default(0);
            $table->decimal('employer_rate', 5, 2)->default(0);
            // Riel a month — the wage counted is clamped between these.
            $table->decimal('min_wage', 15, 2)->default(0);
            $table->decimal('max_wage', 15, 2)->nullable();
            // The staff share comes off before Tax on Salary is worked out.
            $table->boolean('reduces_taxable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff_payroll_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->unique()->constrained('staff')->cascadeOnDelete();
            $table->boolean('tax_resident')->default(true);
            $table->boolean('spouse_dependent')->default(false);
            $table->unsignedTinyInteger('child_dependents')->default(0);
            $table->boolean('social_security_enrolled')->default(true);
            $table->string('social_security_number', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('staff_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('type', 20); // loan | advance
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->date('issued_on');
            // Taken from each payroll from `first_deduction_on` on, until paid back.
            $table->decimal('installment_amount', 15, 2);
            $table->date('first_deduction_on');
            $table->string('status', 20)->default('active'); // active | settled | cancelled
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'status']);
        });

        Schema::create('staff_loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_loan_id')->constrained('staff_loans')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('paid_on');
            $table->string('method', 20); // payroll | cash
            // Set by the payroll run that took it (stage 3).
            $table->unsignedBigInteger('payroll_run_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('payroll_run_id');
        });

        $now = now();

        DB::table('payroll_settings')->insert(['created_at' => $now, 'updated_at' => $now]);

        // Cambodia's monthly Tax on Salary (residents), in riel.
        DB::table('tax_brackets')->insert([
            ['min_amount' => 0, 'max_amount' => 1500000, 'rate' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['min_amount' => 1500000, 'max_amount' => 2000000, 'rate' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['min_amount' => 2000000, 'max_amount' => 8500000, 'rate' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['min_amount' => 8500000, 'max_amount' => 12500000, 'rate' => 15, 'created_at' => $now, 'updated_at' => $now],
            ['min_amount' => 12500000, 'max_amount' => null, 'rate' => 20, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // NSSF — wage counted between 400,000 and 1,200,000 riel a month.
        DB::table('social_security_schemes')->insert([
            ['code' => 'OR', 'name' => 'Occupational risk', 'employee_rate' => 0, 'employer_rate' => 0.8, 'min_wage' => 400000, 'max_wage' => 1200000, 'reduces_taxable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'HC', 'name' => 'Health care', 'employee_rate' => 0, 'employer_rate' => 2.6, 'min_wage' => 400000, 'max_wage' => 1200000, 'reduces_taxable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PEN', 'name' => 'Pension', 'employee_rate' => 2, 'employer_rate' => 2, 'min_wage' => 400000, 'max_wage' => 1200000, 'reduces_taxable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_loan_repayments');
        Schema::dropIfExists('staff_loans');
        Schema::dropIfExists('staff_payroll_profiles');
        Schema::dropIfExists('social_security_schemes');
        Schema::dropIfExists('tax_brackets');
        Schema::dropIfExists('payroll_settings');
    }
};

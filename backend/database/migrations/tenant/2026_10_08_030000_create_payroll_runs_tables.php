<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Payroll's runs (stage 3).
 *
 * - payroll_runs: one payroll for a pay period — a month, or one half of
 *   it — going draft → pending (approval) → approved → paid, or rejected /
 *   cancelled. Paying it posts the net pay to Accounting as one expense and
 *   takes the loan installments.
 * - payslips: one per staff member in a run, everything worked out and kept
 *   as it was paid — amounts in the staff member's salary currency, the
 *   line by line breakdown, and their bank details at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30);
            $table->char('month', 7); // 2026-10
            $table->string('period', 20); // month | first_half | second_half
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');
            $table->string('status', 20)->default('draft'); // draft | pending | approved | rejected | paid | cancelled
            // KHR per USD used for tax/NSSF (and for the expense, if needed).
            $table->decimal('khr_per_usd', 12, 4)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('calculated_at')->nullable();
            // Who sent it for approval — the "requester" ApprovalFlow tells about each step.
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->timestamps();

            $table->index(['month', 'period']);
            $table->index('status');
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->decimal('monthly_basic', 15, 2);
            $table->decimal('basic_pay', 15, 2);
            $table->decimal('allowances', 15, 2)->default(0);
            $table->decimal('bonuses', 15, 2)->default(0);
            $table->decimal('overtime_pay', 15, 2)->default(0);
            $table->decimal('gross_pay', 15, 2);
            $table->decimal('attendance_deduction', 15, 2)->default(0);
            $table->decimal('other_deductions', 15, 2)->default(0);
            $table->decimal('social_security_employee', 15, 2)->default(0);
            $table->decimal('social_security_employer', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('loan_deduction', 15, 2)->default(0);
            // A one-off change to the net pay, typed in before approval (kept when recalculated).
            $table->decimal('adjustment', 15, 2)->default(0);
            $table->text('adjustment_note')->nullable();
            $table->decimal('net_pay', 15, 2);
            // Every line, and the working (overtime minutes, absences, tax base, loans...).
            $table->json('lines');
            $table->json('details');
            $table->string('payment_method', 20)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number', 64)->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'staff_id']);
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
    }
};

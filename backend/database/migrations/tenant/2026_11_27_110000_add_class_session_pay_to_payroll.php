<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Payroll and Teachers > Class sessions: pay teachers for sessions they
 * cover (a substitute, or someone else's make-up class) at their hourly rate
 * times `class_cover_rate`, and take off missed class sessions at the hourly
 * rate. Both off until the school turns them on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->boolean('pay_class_cover')->default(false);
            $table->decimal('class_cover_rate', 5, 2)->default(1);
            $table->boolean('deduct_missed_sessions')->default(false);
        });
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('cover_pay', 15, 2)->default(0)->after('overtime_pay');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('cover_pay');
        });
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropColumn(['pay_class_cover', 'class_cover_rate', 'deduct_missed_sessions']);
        });
    }
};

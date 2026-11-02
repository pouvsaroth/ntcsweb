<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. HRM > Payroll > Basic salary "Pay by shift": a salary split
 * into a monthly amount per shift (Shift A Mon–Fri $70, Shift B Sat–Sun $100).
 * The salary's basic_salary is their sum. Payroll works out each shift's own
 * daily / hourly rate from its amount, so an absence on a Shift B day comes
 * off at Shift B's rate. A salary with no rows here is a plain monthly one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_salary_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_salary_id')->constrained('staff_salaries')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            // Monthly, in the salary's currency.
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['staff_salary_id', 'shift_id']);
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'staff_salary_shifts');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_salary_shifts');
    }
};

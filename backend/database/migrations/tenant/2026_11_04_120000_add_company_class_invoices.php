<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing > Invoices > Invoice by class: one invoice to a company for a whole
 * company class (Billing > Companies), at one total for a period — not per
 * student. Such an invoice has company_id + class_id and no student_id; every
 * other invoice still has a student. Its payments have no student either.
 *
 * invoice_students keeps the class's student list as it was when the invoice
 * was made (code and name copied), so an old invoice still shows who it
 * covered after students leave the class or change their names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
            $table->foreignId('company_id')->nullable()->after('student_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('class_id')->nullable()->after('company_id')->constrained('classes')->restrictOnDelete();
            $table->date('period_from')->nullable()->after('due_date');
            $table->date('period_to')->nullable()->after('period_from');

            $table->index('company_id');
            $table->index('class_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
        });

        DB::connection($this->getConnection())->statement(
            'alter table invoices add constraint invoices_bill_to_check check (student_id is not null or company_id is not null)'
        );

        Schema::create('invoice_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->string('student_code', 64)->nullable();
            $table->string('student_name');
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['invoice_id', 'student_id']);
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'invoice_students');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_students');
        DB::connection($this->getConnection())->statement('alter table invoices drop constraint if exists invoices_bill_to_check');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['period_from', 'period_to']);
        });
    }
};

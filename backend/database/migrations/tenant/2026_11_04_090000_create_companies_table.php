<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Billing > Companies: a company the school teaches a class
 * for. The company pays one total for the whole class, not per student, so a
 * class with company_id set is a "company class": students are added to it
 * without their own invoices, and the company is invoiced instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('academic_year_id')->constrained('companies')->restrictOnDelete();
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'companies');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
        Schema::dropIfExists('companies');
    }
};

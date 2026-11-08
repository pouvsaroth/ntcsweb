<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Invoices > Discount codes: a code a student uses when they
 * enroll for money off the fee. Public codes work for anyone; personal codes
 * only for the students they are given to. No chosen courses = every course.
 * Each use is a redemption; a cancelled invoice/enrollment releases it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('audience', 20)->default('public'); // public | personal
            $table->string('discount_type', 20); // percent | amount
            $table->decimal('value', 12, 2);
            // A percent code's most it can take off (null = no cap), in `currency`.
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->string('currency', 3);
            $table->date('starts_on');
            $table->date('expires_on');
            $table->unsignedInteger('max_uses')->nullable(); // null = no total limit
            $table->string('status', 20)->default('active'); // active | inactive
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_on']);
        });

        Schema::create('discount_code_course_package', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_code_id')->constrained('discount_codes')->cascadeOnDelete();
            $table->foreignId('course_package_id')->constrained('course_packages')->cascadeOnDelete();
            $table->unique(['discount_code_id', 'course_package_id']);
        });

        Schema::create('discount_code_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_code_id')->constrained('discount_codes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['discount_code_id', 'student_id']);
        });

        Schema::create('discount_code_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_code_id')->constrained('discount_codes')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('status', 20)->default('applied'); // applied | released
            $table->unsignedBigInteger('used_by')->nullable();
            $table->timestamps();

            $table->index(['discount_code_id', 'status']);
            $table->index('invoice_id');
        });

        $db = DB::connection($this->getConnection());
        // Once per student: only one use that is still applied.
        $db->statement("CREATE UNIQUE INDEX discount_code_redemptions_one_per_student ON discount_code_redemptions USING btree (discount_code_id, student_id) WHERE status = 'applied'");

        foreach (['discount_codes', 'discount_code_course_package', 'discount_code_student', 'discount_code_redemptions'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_code_redemptions');
        Schema::dropIfExists('discount_code_student');
        Schema::dropIfExists('discount_code_course_package');
        Schema::dropIfExists('discount_codes');
    }
};

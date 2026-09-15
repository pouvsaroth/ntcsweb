<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How this invoice is billed — the same fee tier keys used on CoursePackage
 * (monthly/term/video/monthly_online/term_online). For a package-enrollment
 * invoice this is the `fee_type` the enrollment already chose (see
 * EnrollmentService::enrollInPackage()); for a manual invoice it's whatever
 * staff select on the form, and null when the invoice isn't a course fee at
 * all (e.g. a one-off product sale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_type', 20)->nullable()->after('intended_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('payment_type');
        });
    }
};

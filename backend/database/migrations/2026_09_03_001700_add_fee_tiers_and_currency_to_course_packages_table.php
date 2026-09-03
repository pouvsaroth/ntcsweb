<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A package is actually sold multiple ways (in-person vs online, monthly vs
 * per-term vs video-only) — these 5 tiers are the real, user-entered prices
 * from now on. `price` itself stays: it still feeds the untouched billing
 * pipeline (Product, Enrollment::$fee, InvoiceItem snapshots — see
 * EnrollmentService::enrollInPackage()), just derived server-side from
 * whichever fee is set (see CoursePackageService) instead of typed directly.
 * Picking which fee tier a given enrollment is actually billed at is a
 * later feature — out of scope here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->decimal('fee_monthly', 10, 2)->nullable()->after('price');
            $table->decimal('fee_term', 10, 2)->nullable()->after('fee_monthly');
            $table->decimal('fee_video', 10, 2)->nullable()->after('fee_term');
            $table->decimal('fee_monthly_online', 10, 2)->nullable()->after('fee_video');
            $table->decimal('fee_term_online', 10, 2)->nullable()->after('fee_monthly_online');
            $table->string('currency', 3)->default('USD')->after('fee_term_online');
        });

        DB::statement('UPDATE course_packages SET fee_monthly = price WHERE fee_monthly IS NULL');
    }

    public function down(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->dropColumn(['fee_monthly', 'fee_term', 'fee_video', 'fee_monthly_online', 'fee_term_online', 'currency']);
        });
    }
};

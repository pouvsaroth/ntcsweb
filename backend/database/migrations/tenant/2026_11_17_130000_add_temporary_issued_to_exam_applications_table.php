<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Examination → Certificate: a temporary certificate (printed with the
 * បណ្តោះអាសន្ន watermark) handed out before the real one — the day it was
 * given and who recorded it. The real one stays certificate_issued_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->date('temporary_issued_date')->nullable();
            $table->unsignedBigInteger('temporary_issued_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->dropColumn(['temporary_issued_date', 'temporary_issued_by']);
        });
    }
};

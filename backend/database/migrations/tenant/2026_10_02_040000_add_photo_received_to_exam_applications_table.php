<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Examination → Certificate: a student who passed (score ≥ 85, see
 * ExamMention::PASS_SCORE) brings in a photo for their certificate; staff
 * record the day it was received and an optional remark. No DB-level
 * foreign key on `photo_received_by`, same style as `decided_by` — `users`
 * lives in the central database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->date('photo_received_date')->nullable();
            $table->text('photo_received_remark')->nullable();
            $table->unsignedBigInteger('photo_received_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->dropColumn(['photo_received_date', 'photo_received_remark', 'photo_received_by']);
        });
    }
};

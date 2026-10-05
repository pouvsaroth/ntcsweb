<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Examination → Certificate:
 *
 * - `exam_scores.academic_year_id` — the academic year a score was entered
 *   in (the current one at the time, see ExamScoreService::record()), shown
 *   and filterable on the Certificate tab. Existing scores are backfilled
 *   from the academic year whose start–end dates contain the day the score
 *   was recorded (the latest-starting one if years overlap); a score outside
 *   every year stays NULL.
 *
 * - `exam_applications.certificate_issued_*` — the day the certificate was
 *   handed to the student, same shape as `photo_received_*` (no DB-level
 *   foreign key on `_by`, since `users` lives in the central database).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_scores', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE exam_scores
            SET academic_year_id = (
                SELECT academic_years.id
                FROM academic_years
                WHERE CAST(exam_scores.recorded_at AS DATE) BETWEEN academic_years.start_date AND academic_years.end_date
                ORDER BY academic_years.start_date DESC
                LIMIT 1
            )
            WHERE academic_year_id IS NULL AND recorded_at IS NOT NULL
        SQL);

        Schema::table('exam_applications', function (Blueprint $table) {
            $table->date('certificate_issued_date')->nullable();
            $table->text('certificate_issued_remark')->nullable();
            $table->unsignedBigInteger('certificate_issued_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->dropColumn(['certificate_issued_date', 'certificate_issued_remark', 'certificate_issued_by']);
        });

        Schema::table('exam_scores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_id');
        });
    }
};

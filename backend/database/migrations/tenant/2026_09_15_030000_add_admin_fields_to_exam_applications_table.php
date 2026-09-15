<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Lets an admin create/edit an exam application directly (the
 * "Exam Application" tab) rather than only approving/rejecting one a student
 * already submitted — see AdminExamApplicationController.
 *
 * `exam_date`/`exam_time`/`table_no`/`fee_amount`/`fee_currency`/
 * `student_marked_paid_at` all become nullable: the admin Application Form
 * only requires "which student, which status" (see the legacy form's own
 * asterisks — scheduling/fee fields carry none). The student self-service
 * flow (StoreMyExamApplicationRequest) still requires exam_date/exam_time/
 * table_no at the validation layer — this only relaxes the column itself.
 *
 * `sold_at`/`received_at`/`paid_back_at` are three independent, optional
 * timestamps an admin stamps from the grid's toolbar (Sell Word / Receive
 * Word / Pay Back Exam) — plain markers of where a paper exam-booklet
 * transaction stands, not a state machine tied to `status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->date('exam_date')->nullable()->change();
            $table->time('exam_time')->nullable()->change();
            $table->string('table_no', 20)->nullable()->change();
            $table->string('fee_currency', 3)->nullable()->change();
            $table->timestamp('student_marked_paid_at')->nullable()->change();

            $table->string('file_code', 50)->nullable()->after('student_id');
            $table->string('room_number', 20)->nullable()->after('table_no');
            $table->time('exam_time_out')->nullable()->after('exam_time');
            $table->text('remark')->nullable()->after('decision_reason');
            $table->timestamp('sold_at')->nullable()->after('remark');
            $table->timestamp('received_at')->nullable()->after('sold_at');
            $table->timestamp('paid_back_at')->nullable()->after('received_at');
        });

        Schema::table('exam_applications', function (Blueprint $table) {
            $table->decimal('fee_amount', 10, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->dropColumn(['file_code', 'room_number', 'exam_time_out', 'remark', 'sold_at', 'received_at', 'paid_back_at']);
        });
    }
};

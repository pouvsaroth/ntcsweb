<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a student pays the exam fee when applying online (docs/academics/exam-fee-payment.md):
 * cash at school, or the school's KHQR with the fee amount. A QR payment is
 * found through Bakong by the code's MD5, the same way a plan sign-up is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->string('payment_method', 10)->nullable()->after('student_marked_paid_at');
            $table->text('khqr')->nullable()->after('payment_method');
            $table->string('khqr_md5', 32)->nullable()->index()->after('khqr');
            $table->timestamp('khqr_expires_at')->nullable()->after('khqr_md5');
            $table->timestamp('khqr_checked_at')->nullable()->after('khqr_expires_at');
            $table->timestamp('paid_at')->nullable()->after('khqr_checked_at');
            $table->decimal('paid_amount', 12, 2)->nullable()->after('paid_at');
            $table->string('paid_currency', 3)->nullable()->after('paid_amount');
            $table->string('bakong_hash')->nullable()->after('paid_currency');
        });
    }

    public function down(): void
    {
        Schema::table('exam_applications', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'khqr', 'khqr_md5', 'khqr_expires_at', 'khqr_checked_at', 'paid_at', 'paid_amount', 'paid_currency', 'bakong_hash']);
        });
    }
};

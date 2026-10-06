<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Recruitment > Offer letter: the job offered to a selected
 * applicant — pay, start date, terms — and their answer. Once accepted and
 * the person is added as staff, `hired_staff_id` links the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();
            $table->foreignId('job_position_id')->nullable()->constrained('job_positions')->nullOnDelete();
            $table->string('position_title');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('employment_type', 20);
            $table->decimal('salary', 12, 2);
            $table->string('salary_currency', 3)->default('USD');
            $table->date('start_date');
            $table->unsignedTinyInteger('probation_months')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('benefits')->nullable();
            $table->text('terms')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('hired_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_letters');
    }
};

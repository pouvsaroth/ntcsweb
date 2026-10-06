<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Recruitment's candidates and their files:
 *
 * - applicants: someone who applied (from the public Careers page, or added
 *   by HR), for a job or as a general application, and where they are in
 *   the hiring process (`stage`).
 * - applicant_documents: their CV and other files — stored on the private
 *   disk, only ever downloaded through an authorised endpoint.
 *
 * Deleting an applicant deletes their documents (and the files, see
 * Applicant::booted()) — personal data isn't kept once HR removes someone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_position_id')->nullable()->constrained('job_positions')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('source', 20);
            $table->decimal('expected_salary', 12, 2)->nullable();
            $table->date('available_from')->nullable();
            $table->text('cover_letter')->nullable();
            $table->string('stage', 20)->default('new');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('stage');
            $table->index('phone');
        });

        Schema::create('applicant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            // Null when the applicant uploaded it themselves (Careers page).
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_documents');
        Schema::dropIfExists('applicants');
    }
};

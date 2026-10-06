<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Recruitment's vacancies and where they're advertised:
 *
 * - job_positions: a job HR has opened, usually from an approved manpower
 *   request — what, where, how many, pay range, and whether it's still open.
 * - job_postings: one advert of a job on one channel (the school website,
 *   Facebook, Telegram, ...). A Website posting that's active and not
 *   expired, of a job that's still open, is listed on the public Careers page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manpower_request_id')->nullable()->constrained('manpower_requests')->nullOnDelete();
            $table->string('title');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedInteger('headcount')->default(1);
            $table->string('employment_type', 20);
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->string('salary_currency', 3)->default('USD');
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->string('status', 20)->default('open');
            $table->date('opened_on')->nullable();
            $table->date('closes_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_position_id')->constrained('job_positions')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('url', 500)->nullable();
            $table->date('posted_on');
            $table->date('expires_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['channel', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
        Schema::dropIfExists('job_positions');
    }
};

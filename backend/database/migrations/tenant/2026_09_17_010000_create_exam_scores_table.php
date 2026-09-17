<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. One score per exam application — an application is already
 * one student sitting one Book's exam for one enrollment (see the
 * 2026_09_15_040000 migration's docblock), so the application itself is the
 * natural key; a student with no application simply has nothing to score.
 * Only approved applications are accepted, enforced in
 * ExamScoreService rather than here, since an application's status can
 * still change after the fact. `score` is 0–100 with two decimals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exam_application_id')->unique()->constrained('exam_applications')->cascadeOnDelete();
            $table->decimal('score', 5, 2);
            $table->string('remark', 500)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('recorded_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_scores');
    }
};

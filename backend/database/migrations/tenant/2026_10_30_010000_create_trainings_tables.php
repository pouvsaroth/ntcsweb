<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Professional Development (docs/hrm/professional-development.md):
 *
 *  - trainings: a training session or course — what, who runs it, where and
 *    when (Training Calendar). External courses a staff member went to are
 *    recorded the same way.
 *  - training_participants: the staff assigned to a training, their
 *    attendance and result (Training Records) and the certificate they got
 *    (Certificates), which is also kept as a certificate in
 *    Teachers > Qualification (teacher_qualification_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('category', 100)->nullable();
            // internal (run by the school) | external (outside provider)
            $table->string('provider_type', 20)->default('internal');
            $table->string('trainer', 200)->nullable();
            // in_person | online
            $table->string('mode', 20)->default('in_person');
            $table->string('location', 200)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('hours', 6, 2)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->text('description')->nullable();
            // planned | completed | cancelled
            $table->string('status', 20)->default('planned');
            // No DB-level foreign key — users live in the central database.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });

        Schema::create('training_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            // registered | attended | absent
            $table->string('attendance', 20)->default('registered');
            // passed | failed (null = not assessed)
            $table->string('result', 20)->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->text('note')->nullable();
            $table->string('certificate_no', 100)->nullable();
            $table->date('certificate_issued_on')->nullable();
            $table->date('certificate_expires_on')->nullable();
            $table->string('certificate_file_path')->nullable();
            $table->string('certificate_file_name')->nullable();
            $table->string('certificate_mime', 120)->nullable();
            $table->unsignedBigInteger('certificate_size')->nullable();
            $table->foreignId('teacher_qualification_id')->nullable()->constrained('teacher_qualifications')->nullOnDelete();
            $table->timestamps();

            $table->unique(['training_id', 'staff_id']);
            $table->index('staff_id');
        });

        $db = DB::connection();
        SchoolTables::secure($db, 'trainings');
        SchoolTables::secure($db, 'training_participants');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('training_participants');
        Schema::dropIfExists('trainings');
    }
};

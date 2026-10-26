<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A student's request to change class (eApprovals > My Request > Request to
 * change class): the enrollment, the class wanted and the day they start
 * there. Decided in Approvals; approving it moves the enrollment
 * (EnrollmentService::transferClass) and the transfer history row keeps the
 * start date and which request it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('from_class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('to_class_id')->constrained('classes')->restrictOnDelete();
            $table->date('start_date');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('student_id');
        });

        Schema::table('enrollment_transfer_histories', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('to_course_package_id');
            $table->foreignId('class_change_request_id')->nullable()->after('start_date')->constrained('class_change_requests')->nullOnDelete();
        });

        $db = DB::connection();
        SchoolTables::secure($db, 'class_change_requests');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::table('enrollment_transfer_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_change_request_id');
            $table->dropColumn('start_date');
        });
        Schema::dropIfExists('class_change_requests');
    }
};

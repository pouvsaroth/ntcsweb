<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Student record (docs/academics/student-record.md): each change of a
 * student's status — studying, transferred out (to another school), dropped
 * out (withdrawn), graduated — with its date and reason, and the school a
 * transferred student went to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->date('effective_date')->nullable();
            $table->string('reason')->nullable();
            $table->string('to_school')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->index('student_id');
        });

        $db = DB::connection();
        SchoolTables::secure($db, 'student_status_histories');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('student_status_histories');
    }
};

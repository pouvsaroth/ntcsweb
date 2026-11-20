<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Parent portal: which students a parent account may see.
 * Kept apart from student_guardians, which are re-created on every student
 * save; matched to a guardian by phone. One parent login can have several
 * children (siblings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_students', function (Blueprint $table) {
            $table->id();
            // A central login (users live in the central database: no foreign key).
            $table->unsignedBigInteger('user_id');
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            // Father, Mother, Guardian... (the guardian type when access was given).
            $table->string('relation', 50)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'student_id']);
            $table->index('student_id');
            $table->index('user_id');
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'parent_students');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_students');
    }
};

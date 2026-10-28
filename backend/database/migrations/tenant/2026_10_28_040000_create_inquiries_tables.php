<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Front Office > Inquiry: people who ask about the school (walk-in, phone,
 * Facebook…), what they are interested in, and every follow-up made with
 * them until they enrol (linked to the new student) or lose interest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->date('inquiry_date');
            $table->string('name', 150);
            $table->string('gender', 10)->nullable();
            $table->string('phone', 40);
            $table->string('email', 150)->nullable();
            $table->string('interested_in', 255)->nullable();
            $table->string('source', 20);
            $table->string('status', 20)->default('new');
            $table->date('next_follow_up_on')->nullable();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->text('note')->nullable();
            // No DB-level foreign key — users live in the central database.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_follow_up_on']);
            $table->index('inquiry_date');
        });

        Schema::create('inquiry_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->cascadeOnDelete();
            $table->date('followed_up_on');
            $table->string('method', 20);
            $table->text('note');
            $table->date('next_follow_up_on')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            // Who wrote it, kept as text: the user lives in the central database.
            $table->string('created_by_name', 150)->nullable();
            $table->timestamps();

            $table->index(['inquiry_id', 'followed_up_on']);
        });

        $db = DB::connection();
        SchoolTables::secure($db, 'inquiries');
        SchoolTables::secure($db, 'inquiry_follow_ups');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_follow_ups');
        Schema::dropIfExists('inquiries');
    }
};

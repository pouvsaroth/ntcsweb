<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Front Office > Meetings: when and where, the agenda, who takes part
 * (staff, notified; outside guests as text) and the meeting note written
 * during or after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->date('meeting_date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->string('location', 200)->nullable();
            $table->text('agenda')->nullable();
            $table->text('minutes')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('organizer_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            // Outside guests, one per line.
            $table->text('guests')->nullable();
            // No DB-level foreign key — users live in the central database.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['meeting_date', 'start_time']);
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['meeting_id', 'staff_id']);
        });

        $db = DB::connection();
        SchoolTables::secure($db, 'meetings');
        SchoolTables::secure($db, 'meeting_participants');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};

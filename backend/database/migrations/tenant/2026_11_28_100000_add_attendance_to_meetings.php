<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Front Office > Meeting arrangement: who came. Each staff participant gets
 * present / late / absent / excused on its meeting_participants row; guests
 * (free text, one per line) get theirs by name in meetings.guest_attendance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_participants', function (Blueprint $table) {
            $table->string('attendance', 20)->nullable();
        });
        Schema::table('meetings', function (Blueprint $table) {
            $table->json('guest_attendance')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('guest_attendance');
        });
        Schema::table('meeting_participants', function (Blueprint $table) {
            $table->dropColumn('attendance');
        });
    }
};

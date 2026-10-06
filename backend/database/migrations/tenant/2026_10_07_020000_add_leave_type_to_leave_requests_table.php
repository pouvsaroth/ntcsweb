<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Leave Management: a staff member's leave request now says which
 * leave type it is, whether it's a full day or a morning/afternoon half
 * (`day_part`, only for a one-day request), and how many working days it
 * takes off their balance (`days` — worked out on submit from their work
 * schedule and the holidays, in half days). All null on a student's request
 * and on staff requests filed before this — those count against no balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->restrictOnDelete();
            $table->string('day_part', 10)->nullable(); // full | morning | afternoon
            $table->decimal('days', 5, 1)->nullable();

            $table->index(['staff_id', 'leave_type_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex(['staff_id', 'leave_type_id', 'status']);
            $table->dropConstrainedForeignId('leave_type_id');
            $table->dropColumn(['day_part', 'days']);
        });
    }
};

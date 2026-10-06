<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM > Leave Management: days added to (or taken from) a staff member's
 * yearly balance of a leave type, on top of what their policy gives.
 *
 * - kind `adjustment`: HR's manual change, with a note (days may be
 *   negative).
 * - kind `carry_forward`: unused days moved in from the year before (Carry
 *   forward tab) — one per staff, type and year; running carry forward again
 *   replaces it. Usable only for leave up to `expires_on`, when set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('kind', 20); // adjustment | carry_forward
            $table->decimal('days', 5, 1);
            $table->date('expires_on')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'year', 'leave_type_id']);
            $table->index(['year', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_entries');
    }
};

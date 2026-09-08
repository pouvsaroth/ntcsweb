<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-teaching personnel (Accountant, HR, Librarian, IT Officer, ...) —
 * Teacher stays its own dedicated table/flow, untouched by this migration.
 *
 * `user_id` is nullable for the same reason as Teacher's: removing the login
 * account must not take the staff member's historical record with it. In
 * practice StaffController::store() always populates it in the same
 * transaction that creates the row, since Staff creation auto-provisions the
 * User from the selected Position's Role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();

            // No DB-level foreign key: `users`/`positions` haven't moved to a
            // per-tenant database yet, and a cross-database foreign key isn't
            // possible in Postgres regardless.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('position_id');

            $table->string('employee_code', 32);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 32);
            $table->date('hire_date')->nullable();
            $table->string('status', 20)->default('active'); // active | inactive

            $table->timestamps();
            $table->softDeletes();

            $table->unique('employee_code');
            $table->unique('user_id');
            $table->index('status');
            $table->index('position_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};

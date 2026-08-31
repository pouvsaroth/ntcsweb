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

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Restrict: a Position with existing staff must be reassigned away
            // from, not deleted out from under them.
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();

            $table->string('employee_code', 32);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 32);
            $table->date('hire_date')->nullable();
            $table->string('status', 20)->default('active'); // active | inactive

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'employee_code']);
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'position_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};

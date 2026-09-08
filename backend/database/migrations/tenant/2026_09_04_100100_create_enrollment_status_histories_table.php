<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log of every status change on an enrollment (see
 * EnrollmentService::changeStatus()) — the "history" half of "manage student
 * status and history." Never updated or deleted once written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->date('effective_date')->nullable();
            // No DB-level foreign key: `users` hasn't moved to a per-tenant
            // database yet, and a cross-database foreign key isn't possible
            // in Postgres regardless.
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->index('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_status_histories');
    }
};

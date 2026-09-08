<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. One row per assignment — never updated in place to point at
 * a new holder; assigning to someone new closes the open row
 * (`returned_date` set, `status` = RETURNED) and inserts a fresh one, so the
 * full custody history survives. `assignable_type`/`assignable_id` covers
 * Staff/Student/User/Department/Classroom in one table — see
 * App\Support\Assets\AssignableType for the whitelist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            // Already has no DB-level foreign key by nature (Staff/Student/
            // User/Department/Classroom all in one polymorphic column) —
            // Department is the only one of those that's moved here so far.
            $table->nullableMorphs('assignable');

            // No DB-level foreign key: `users` hasn't moved to a per-tenant
            // database yet, and a cross-database foreign key isn't possible
            // in Postgres regardless.
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->date('assigned_date');
            $table->date('expected_return_date')->nullable();
            $table->date('returned_date')->nullable();
            $table->string('condition_at_assignment', 20)->nullable();
            $table->string('condition_at_return', 20)->nullable();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | RETURNED
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
    }
};

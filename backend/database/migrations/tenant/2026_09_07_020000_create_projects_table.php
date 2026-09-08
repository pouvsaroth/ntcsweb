<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Kanban-style project — see ProjectColumn (its board's lanes, ordered and
 * fully admin-defined, not a fixed To-Do/In-Progress/Done set) and
 * ProjectTask (the cards within a column). Any staff/admin can create and
 * use projects — see ProjectPolicy's docblock — there is no per-project
 * membership gate.
 *
 * Lives in the school's own database (see BelongsToTenant's docblock) — no
 * `tenant_id` column. `created_by` stays a plain bigint with no DB-level
 * foreign key: `users` hasn't moved to a per-tenant database yet, and a
 * cross-database foreign key isn't possible in Postgres regardless — see
 * Project::creator(), which still resolves correctly as an ordinary
 * separate query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active'); // active | archived

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

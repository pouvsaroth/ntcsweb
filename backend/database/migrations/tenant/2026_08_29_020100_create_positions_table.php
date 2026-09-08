<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job title that carries a Role — see Staff, which belongs to one. Kept as
 * its own table (not a Role subtype) because a Position also carries HR-ish
 * metadata (description, active/inactive) that has nothing to do with
 * authorization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();

            // No DB-level foreign key: `roles` hasn't moved to a per-tenant
            // database yet, and a cross-database foreign key isn't possible
            // in Postgres regardless.
            $table->unsignedBigInteger('role_id');

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active'); // active | inactive

            $table->timestamps();
            $table->softDeletes();

            $table->unique('name');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};

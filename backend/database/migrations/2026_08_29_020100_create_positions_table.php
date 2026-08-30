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

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Restrict, not cascade/null: a Role backing a live Position must be
            // retired (reassign the Position first) rather than silently
            // vanishing out from under it.
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active'); // active | inactive

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};

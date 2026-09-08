<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. A physical (or virtual) room a class can be scheduled into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('location')->nullable();
            $table->string('status', 20)->default('active'); // active | inactive

            $table->timestamps();
            $table->softDeletes();

            $table->unique('name');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};

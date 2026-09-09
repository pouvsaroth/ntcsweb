<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Each school manages its own textbook/material catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('author')->nullable();
            $table->string('isbn', 32)->nullable();
            $table->string('publisher')->nullable();
            $table->text('description')->nullable();
            $table->string('cover_image')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 20)->default('active'); // active | inactive

            $table->timestamps();
            $table->softDeletes();

            // Search is the only real access pattern for a catalog table.
            $table->index('title');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};

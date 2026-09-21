<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared label/tag catalog Kanban cards pick from (Backend, Frontend,
 * Bug, ...) — tenant-wide, not scoped to one project, same convention as
 * BookCategory: a small dedicated admin-managed table rather than the
 * heavier multilingual Base Data lookup system, since a label needs no
 * per-language translation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_labels', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('color', 20)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_labels');
    }
};

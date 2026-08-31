<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. The public marketing catalog of courses/programs a school
 * offers (e.g. "Computer Basic", "Web Development") — distinct from
 * `classes` (an actual scheduled teaching group with a teacher/room/roster).
 * A program is what a visitor browses on the public site before enrolling;
 * a class is the operational record of one running batch of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('title');
            // Secondary line under the title (e.g. an English name alongside
            // a Khmer title) — free text, not a locale-specific translation
            // field, since the platform doesn't localize admin-entered
            // content per viewer language.
            $table->string('subtitle')->nullable();
            $table->string('category');
            $table->string('level', 20)->default('beginner'); // beginner | intermediate | advanced
            // Free text ("2 days", "3 months") rather than a number+unit pair
            // — schools describe course length inconsistently enough that a
            // rigid structure would just get fought with free text anyway.
            $table->string('duration_label', 50)->nullable();
            $table->text('description')->nullable();

            // Storage-relative path, same convention as home_slides/students
            // — HomeSlide::imageUrl()'s pattern, not a full URL.
            $table->string('image_path')->nullable();

            // The homepage's "Popular Programs" section shows only these,
            // ordered by sort_order; the full /programs catalog page shows
            // every active program regardless of this flag.
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active'); // active | inactive

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'is_featured', 'sort_order']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};

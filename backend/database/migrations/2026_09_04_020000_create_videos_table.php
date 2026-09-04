<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A YouTube video lesson attached to one CoursePackage — the public "Video
 * Lesson" page groups these by course. `video_url` is the plain YouTube
 * link an admin pastes in; the YouTube video id (and therefore the
 * thumbnail/embed URLs) is derived from it at read time — see Video::
 * youtubeId()/thumbnailUrl()/embedUrl() — rather than stored redundantly.
 * `thumbnail_path` is an optional admin-uploaded override for when YouTube's
 * own thumbnail isn't good enough.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_package_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('video_url');
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active'); // active | inactive
            $table->timestamps();
            $table->softDeletes();

            // The public Video Lesson page: "this course's active videos, in
            // order" and "the tenant-wide free-preview ordering" (see
            // Public\VideoLessonController) are the two query shapes this
            // table exists to serve.
            $table->index(['tenant_id', 'course_package_id', 'status', 'sort_order']);
            $table->index(['tenant_id', 'status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};

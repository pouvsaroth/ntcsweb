<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Banner images on the public site's Promotion page — each
 * school manages its own set, shown in `sort_order`. Same shape as
 * `gallery_images` (see that migration) plus a `title`, since a promotion
 * banner usually names what it's advertising, unlike a plain gallery photo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();

            // Storage-relative path (see Tenant::storagePath()), not a full
            // URL — Promotion::imageUrl() resolves it against the active
            // disk, so switching from local storage to S3/R2 later needs no
            // data migration.
            $table->string('image_path');
            $table->string('title')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active'); // active | inactive

            $table->timestamps();
            $table->softDeletes();

            // The public promotion query: this school's active images, in order.
            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};

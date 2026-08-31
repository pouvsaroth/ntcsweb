<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. The public site's photo gallery — each school manages its own
 * set of photos, shown in `sort_order` on the public Gallery page. Same
 * shape as `home_slides` (see that migration), minus the slider-specific
 * fields (title/subtitle/link_url) a gallery photo has no use for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Storage-relative path (see Tenant::storagePath()), not a full
            // URL — GalleryImage::imageUrl() resolves it against the active
            // disk, so switching from local storage to S3/R2 later needs no
            // data migration.
            $table->string('image_path');
            $table->string('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active'); // active | inactive

            $table->timestamps();
            $table->softDeletes();

            // The public gallery query: this school's active photos, in order.
            $table->index(['tenant_id', 'status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_images');
    }
};

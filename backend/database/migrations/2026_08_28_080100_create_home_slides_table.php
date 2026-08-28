<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. The homepage image slider — each school manages its own set
 * of banner images, shown in `sort_order` on the public site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_slides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Storage-relative path (see Tenant::storagePath()), not a full
            // URL — HomeSlide::imageUrl() resolves it against the active
            // disk, so switching from local storage to S3/R2 later needs no
            // data migration.
            $table->string('image_path');
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('link_url')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active'); // active | inactive

            $table->timestamps();
            $table->softDeletes();

            // The public homepage query: this school's active slides, in order.
            $table->index(['tenant_id', 'status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_slides');
    }
};

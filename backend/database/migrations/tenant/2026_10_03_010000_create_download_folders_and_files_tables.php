<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. The public site's Download page — folders of files anyone
 * can download, managed under the admin panel's Upload menu. Folders go two
 * levels deep at most: a top folder (`parent_id` null) may hold sub-folders,
 * a sub-folder may not (enforced in the form requests, see DownloadFolder).
 * Either level may hold files. No DB-level foreign keys, same style as the
 * rest of the tenant schema; deleting a folder removes its contents in
 * DownloadFolder::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active'); // active | inactive

            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('download_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('download_folder_id');
            // Shown on the site and used as the downloaded file's name.
            $table->string('name');
            // Storage-relative path (see Tenant::storagePath()), same as gallery_images.image_path.
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['download_folder_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_files');
        Schema::dropIfExists('download_folders');
    }
};

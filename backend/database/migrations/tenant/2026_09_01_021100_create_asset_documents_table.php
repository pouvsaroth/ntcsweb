<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Every attached file for an asset — invoices, warranty
 * documents, disposal paperwork, and photos, distinguished only by `type`
 * (a photo is just a document with type=PHOTO) so one upload/list/delete
 * surface covers both rather than two near-identical tables. Stored on the
 * `public` disk exactly like ExpenseAttachment — see AssetDocument's own
 * docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            $table->string('type', 30)->default('OTHER');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 100)->nullable();
            $table->string('caption')->nullable();
            // No DB-level foreign key: `users` hasn't moved to a per-tenant
            // database yet, and a cross-database foreign key isn't possible
            // in Postgres regardless.
            $table->unsignedBigInteger('uploaded_by')->nullable();

            $table->timestamps();

            $table->index(['asset_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_documents');
    }
};

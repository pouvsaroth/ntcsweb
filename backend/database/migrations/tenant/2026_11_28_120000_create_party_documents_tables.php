<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Political Party > Documents: the party's own files (images,
 * PDF, Word, Excel, ...) kept on the server in folders of any depth — never
 * published. Files live on the private `local` disk and only go out through
 * the signed-in download route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_document_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('party_document_folders')->cascadeOnDelete();
            $table->string('name', 255);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'name']);
        });

        Schema::create('party_documents', function (Blueprint $table) {
            $table->id();
            // Null = the top level (not in a folder).
            $table->foreignId('folder_id')->nullable()->constrained('party_document_folders')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('file_path', 500);
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['folder_id', 'name']);
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'party_document_folders');
        SchoolTables::secure($db, 'party_documents');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('party_documents');
        Schema::dropIfExists('party_document_folders');
    }
};

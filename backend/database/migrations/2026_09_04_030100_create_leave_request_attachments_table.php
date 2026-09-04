<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A leave request's "Reference" files — a photo or other document, one or
 * more. Same shape as ExpenseAttachment/AssetDocument: a bare file record,
 * no per-file type/caption needed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_attachments');
    }
};

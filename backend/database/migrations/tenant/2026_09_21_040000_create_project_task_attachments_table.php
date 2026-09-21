<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A file attached to a Kanban card — stored on the `public` disk, same convention as ExpenseAttachment/Gallery/avatars. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();

            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 100)->nullable();
            // No DB-level foreign key: `users` hasn't moved to a per-tenant
            // database yet, and a cross-database foreign key isn't possible
            // in Postgres regardless — same reasoning as ExpenseAttachment.
            $table->unsignedBigInteger('uploaded_by')->nullable();

            $table->timestamps();

            $table->index('project_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_attachments');
    }
};

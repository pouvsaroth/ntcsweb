<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. HRM > Staff > Documents (docs/hrm/staff-profile-tabs.md): a
 * staff member's personal / HR files (ID card, CV, contract …). Separate from
 * teacher_documents, which are teaching documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            // id_card | passport | birth_certificate | family_book | cv | contract | certificate | work_permit | medical | other
            $table->string('type', 30);
            $table->string('title', 255);
            $table->string('document_no', 100)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('note', 500)->nullable();
            // Private: the `local` disk, sent only through the download route.
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('staff_id');
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'staff_documents');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_documents');
    }
};

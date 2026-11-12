<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data ops > Databases: databases staff create in the back office ("Other"
 * databases) — empty, a copy of the school template, a copy of another
 * database, or restored from a backup / an uploaded file. No school uses
 * them until someone swaps one in; staff may delete them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_databases', function (Blueprint $table) {
            $table->id();
            $table->string('name', 63)->unique();
            $table->string('source', 20); // empty | template | copy | backup | upload
            $table->string('source_database', 100)->nullable();
            $table->foreignId('backup_id')->nullable()->constrained('bo_backups')->nullOnDelete();
            $table->string('upload_name')->nullable();
            $table->string('status', 20)->default('creating'); // creating | ready | failed
            $table->text('error')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_databases');
    }
};

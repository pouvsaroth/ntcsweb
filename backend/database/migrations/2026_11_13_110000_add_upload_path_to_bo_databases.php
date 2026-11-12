<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Data ops > Restore from an uploaded file: where the file waits (storage/app/restore-uploads) until it is restored. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_databases', function (Blueprint $table) {
            $table->string('upload_path')->nullable()->after('upload_name');
        });
    }

    public function down(): void
    {
        Schema::table('bo_databases', function (Blueprint $table) {
            $table->dropColumn('upload_path');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom backups: only the tables picked, each with its structure and data
 * or its structure only. `tables` lists them: [{name, mode}].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_backups', function (Blueprint $table) {
            $table->json('tables')->nullable()->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('bo_backups', function (Blueprint $table) {
            $table->dropColumn('tables');
        });
    }
};

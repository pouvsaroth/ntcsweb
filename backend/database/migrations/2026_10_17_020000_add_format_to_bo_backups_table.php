<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 4c: a backup is either a whole-database dump (pg_dump, .dump) or one
 * shared school's own rows (.zip of one JSON-lines file per table) — a shared
 * school has no database of its own to dump.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_backups', function (Blueprint $table) {
            $table->string('format', 20)->default('dump')->after('trigger'); // dump | school
        });
    }

    public function down(): void
    {
        Schema::table('bo_backups', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};

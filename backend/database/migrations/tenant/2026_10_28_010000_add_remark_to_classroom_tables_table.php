<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A free-text note on a table (e.g. "near the window", "broken leg"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classroom_tables', function (Blueprint $table) {
            $table->string('remark', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classroom_tables', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
};

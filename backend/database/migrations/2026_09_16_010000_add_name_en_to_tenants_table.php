<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school's own `name` is free-text and often in Khmer (or whichever
 * language the admin typed it in) — `name_en` is an optional English name
 * shown alongside it on the Login page and wherever else branding needs a
 * Latin-script line. Purely cosmetic: nothing else in the app reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('name_en');
        });
    }
};

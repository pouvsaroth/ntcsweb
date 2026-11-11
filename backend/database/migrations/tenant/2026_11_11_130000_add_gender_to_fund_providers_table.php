<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fund > Providers: gender (a code from the GENDER base data list, e.g. male / female). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_providers', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('fund_providers', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};

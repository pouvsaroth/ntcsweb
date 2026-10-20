<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan can include a module but only some of its menus (permission groups).
 * Null = every menu of the plan's modules — what every plan had until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_plans', function (Blueprint $table) {
            $table->json('menus')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bo_plans', function (Blueprint $table) {
            $table->dropColumn('menus');
        });
    }
};

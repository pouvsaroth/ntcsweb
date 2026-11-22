<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back office > Organization types > Menu layout: each type's own sidebar
 * (group order, new groups, items moved / renamed / hidden, tab order and
 * tabs taken out as menu items). Null = the standard School menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_organization_types', function (Blueprint $table) {
            $table->json('menu_layout')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bo_organization_types', fn (Blueprint $table) => $table->dropColumn('menu_layout'));
    }
};

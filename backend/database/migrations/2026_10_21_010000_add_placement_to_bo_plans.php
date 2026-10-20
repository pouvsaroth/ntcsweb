<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a customer on this plan keeps its data: the shared database, or a
 * dedicated database of its own (docs/backoffice/stage-4-database-placement.md).
 * A customer who signs up on the website is set up this way. Existing plans
 * take the platform default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_plans', function (Blueprint $table) {
            $table->string('placement', 20)->default('shared'); // shared | dedicated
        });
        DB::table('bo_plans')->update(['placement' => config('platform.default_placement', 'shared') === 'dedicated' ? 'dedicated' : 'shared']);
    }

    public function down(): void
    {
        Schema::table('bo_plans', function (Blueprint $table) {
            $table->dropColumn('placement');
        });
    }
};

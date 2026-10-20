<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans on the website (docs/backoffice/stage-8-signup.md, 8a): what a plan
 * card on newtechkh.com/plans shows. Off by default — a plan appears there
 * only once staff tick "Show on the website".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_plans', function (Blueprint $table) {
            $table->boolean('show_on_website')->default(false);
            $table->string('name_km', 100)->nullable();
            $table->string('tagline_en', 150)->nullable();
            $table->string('tagline_km', 150)->nullable();
            // One feature per line.
            $table->text('features_en')->nullable();
            $table->text('features_km')->nullable();
            // The ណែនាំ (Recommended) mark — at most one plan, kept so by the controller.
            $table->boolean('recommended')->default(false);
            $table->unsignedSmallInteger('website_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('bo_plans', function (Blueprint $table) {
            $table->dropColumn(['show_on_website', 'name_km', 'tagline_en', 'tagline_km', 'features_en', 'features_km', 'recommended', 'website_order']);
        });
    }
};

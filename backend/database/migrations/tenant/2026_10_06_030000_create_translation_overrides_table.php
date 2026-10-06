<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school's own wording of the app's text (Settings > Language >
 * Translation): one row per locale + message key it changed, e.g.
 * km / adminNav.items.students => "សិស្សានុសិស្ស". The built-in text ships in
 * the frontend's locale files; a row here replaces it for everyone in this
 * school, and deleting the row restores it. See TranslationOverride.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 10);
            $table->string('key', 255);
            $table->text('value');
            $table->timestamps();

            $table->unique(['locale', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_overrides');
    }
};

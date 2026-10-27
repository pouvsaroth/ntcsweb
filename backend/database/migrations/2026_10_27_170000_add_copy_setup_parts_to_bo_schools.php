<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which parts of the other school's setup to copy (SchoolSetupCopier::PARTS);
 * null = all of it. Kept so a retried setup copies the same parts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bo_schools', function (Blueprint $table) {
            $table->json('copy_setup_parts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bo_schools', function (Blueprint $table) {
            $table->dropColumn('copy_setup_parts');
        });
    }
};

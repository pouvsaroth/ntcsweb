<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A program's advertised price on the public marketing catalog — distinct
 * from `books.fee`/`enrollments.fee`, which price an actual class's
 * materials, not the course itself. Nullable: a school may publish a
 * program before pricing it (or intentionally leave it unpriced, e.g.
 * "contact us").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->decimal('fee', 10, 2)->nullable()->after('duration_label');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('fee');
        });
    }
};

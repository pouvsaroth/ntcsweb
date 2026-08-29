<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Different books carry different fees (e.g. an Excel book vs. a Word book
 * in the same class session) — see the `enrollments` migration this pairs
 * with. Nullable: a school may catalog a book before pricing it, and this
 * column is only ever a *default* fee — the actual amount a student is
 * charged is snapshotted onto their own enrollment row, not read live from
 * here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->decimal('fee', 10, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('fee');
        });
    }
};

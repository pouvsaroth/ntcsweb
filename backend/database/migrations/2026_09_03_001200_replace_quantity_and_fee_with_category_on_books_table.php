<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantity and fee never earned their keep on the catalog entry — no stock
 * tracking ever consumed quantity, and fee was only ever a default that
 * Enrollment::$fee immediately snapshots away from. `category` replaces them:
 * a Base Data (LookupCategory "BOOK_CATEGORY") code grouping books the same
 * way Student.gender stores a "GENDER" code — e.g. "MS Word"/"MS Excel"
 * under OFFICE, "Photoshop" under DESIGN, independent of which academic
 * program(s) the book is tagged to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'fee']);
            $table->string('category', 50)->nullable()->after('cover_image');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('category');
            $table->unsignedInteger('quantity')->default(1)->after('cover_image');
            $table->decimal('fee', 10, 2)->nullable()->after('quantity');
        });
    }
};

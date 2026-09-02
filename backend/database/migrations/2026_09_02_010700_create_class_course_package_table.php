<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class <-> Course Package, many-to-many — the "menu" of packages a class
 * session offers, exactly mirroring `class_book`'s own shape (see that
 * migration). No tenant_id of its own — both sides already belong to one
 * tenant. `class_book` itself is untouched; this is an independent,
 * additional menu for the new package-based enrollment path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_course_package', function (Blueprint $table) {
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('course_package_id')->constrained('course_packages')->cascadeOnDelete();

            $table->primary(['class_id', 'course_package_id']);
            $table->index('course_package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_course_package');
    }
};

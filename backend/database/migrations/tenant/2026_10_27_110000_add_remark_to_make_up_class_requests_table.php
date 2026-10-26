<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional remark on a student's make-up class request — where they make
 * it up (at home / at school) and up to which lesson.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('make_up_class_requests', function (Blueprint $table) {
            $table->text('remark')->nullable()->after('to_time');
        });
    }

    public function down(): void
    {
        Schema::table('make_up_class_requests', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
};

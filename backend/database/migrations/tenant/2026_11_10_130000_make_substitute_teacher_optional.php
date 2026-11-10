<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. HRM > Teacher > Substitute: the substitute teacher is
 * optional. No substitute = the class is not held then (teacher away,
 * nobody covers), so no student attendance is needed for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_substitutions', function (Blueprint $table) {
            $table->unsignedBigInteger('substitute_staff_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows saved as "class not held" would break NOT NULL; leave it optional.
    }
};

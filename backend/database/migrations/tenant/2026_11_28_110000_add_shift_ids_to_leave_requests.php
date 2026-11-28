<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff leave by shift: `day_part` = shifts and the shifts they are off in
 * `shift_ids` (a staff member can work several). Its days are the shifts'
 * hours ÷ 8 per working date, to the nearest half day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->json('shift_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('shift_ids');
        });
    }
};

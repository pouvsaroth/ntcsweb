<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Each card design says which sides it has (Front / Back
 * ticks); an unticked side is not designed or printed. Existing designs
 * whose back is empty (no photo, no fields) become front-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_card_designs', function (Blueprint $table) {
            $table->boolean('has_front')->default(true)->after('height_mm');
            $table->boolean('has_back')->default(true)->after('has_front');
        });

        DB::table('staff_card_designs')
            ->whereNull('back_background_path')
            ->where(fn ($q) => $q->whereNull('back_fields')->orWhereJsonLength('back_fields', 0))
            ->update(['has_back' => false]);
    }

    public function down(): void
    {
        Schema::table('staff_card_designs', function (Blueprint $table) {
            $table->dropColumn(['has_front', 'has_back']);
        });
    }
};

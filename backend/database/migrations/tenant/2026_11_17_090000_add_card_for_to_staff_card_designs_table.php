<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Card designs are for staff (HRM > Staff cards) or students
 * (Students > Student cards); existing designs are staff cards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_card_designs', function (Blueprint $table) {
            $table->string('card_for', 10)->default('staff')->after('id');
            $table->index('card_for');
        });
    }

    public function down(): void
    {
        Schema::table('staff_card_designs', function (Blueprint $table) {
            $table->dropIndex(['card_for']);
            $table->dropColumn('card_for');
        });
    }
};

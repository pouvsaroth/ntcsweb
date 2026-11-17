<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Card designs now also cover Examination > Certificate
 * designs: card_for = 'certificate' needs more than 10 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_card_designs', function (Blueprint $table) {
            $table->string('card_for', 20)->default('staff')->change();
        });
    }

    public function down(): void
    {
        Schema::table('staff_card_designs', function (Blueprint $table) {
            $table->string('card_for', 10)->default('staff')->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a self-registered student intends to pay (see
 * StudentRegistrationService), set once at registration and never a real
 * `Payment` until an admin confirms it at approval time — distinct from
 * `Payment::payment_method`, which records how money actually arrived.
 * Null for every invoice created through the ordinary admin flow, which
 * records a real Payment (or none) immediately and has no "intent" to track.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('intended_payment_method', 20)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('intended_payment_method');
        });
    }
};

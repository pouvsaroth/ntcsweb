<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff member's own bank KHQR image (their bank app's "receive money" QR),
 * so the accountant can scan it to pay their salary. Same shape as
 * `signature_path` (a public-disk relative path); see Staff::bankQrUrl().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('bank_qr_path', 500)->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('bank_qr_path');
        });
    }
};

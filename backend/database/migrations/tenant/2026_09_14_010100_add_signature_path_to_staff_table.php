<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff member's own signature image — printed on an invoice/receipt they
 * issue. Same shape as `photo_path` (a public-disk relative path, not a
 * URL); see Staff::signatureUrl()/signaturePath().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('signature_path', 500)->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('signature_path');
        });
    }
};

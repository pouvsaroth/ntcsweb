<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school's own stamp/seal image — printed on invoices/receipts next to
 * the issuing staff member's signature, same storage shape as `logo` (a
 * public-disk relative path, not a URL). See Tenant::stampUrl()/stampPath().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('stamp')->nullable()->after('logo');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('stamp');
        });
    }
};

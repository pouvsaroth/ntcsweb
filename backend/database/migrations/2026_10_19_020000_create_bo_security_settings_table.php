<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → Security (as SmartGPS-V3's back office): whether staff sign-in
 * needs a code from an authenticator app as well as the password. One row,
 * on by default — the way every staff account has signed in until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_security_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('require_two_factor')->default(true);
            $table->timestamps();
        });

        DB::table('bo_security_settings')->insert(['id' => 1, 'require_two_factor' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_security_settings');
    }
};

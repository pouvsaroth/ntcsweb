<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the one-device-at-a-time login rule (AuthService::ensureNoOtherActiveDevice()).
 *
 * Deliberately a flag on the user row, not a lookup into Laravel's own
 * session store: SESSION_DRIVER varies by environment (database locally in
 * CI/tests, redis in this app's real dev/prod config — see .env), and a
 * redis- or array-backed session has no queryable "which user is this"
 * table at all. A plain boolean set at session login and cleared at session
 * logout works identically regardless of driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('session_login_active')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_login_active');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs per-role concurrent-device limits for the *session* transport (the
 * browser SPA — see AuthController's class docblock for the two transports).
 * Replaces the old `session_login_active` boolean, which could only ever
 * represent "zero or one" active session and so couldn't express "a Teacher
 * may have up to three at once" — see User::maxConcurrentDevices() and
 * AuthService::ensureNoOtherActiveDevice().
 *
 * One row per currently-open session, keyed by Laravel's own session id
 * rather than anything session-store-specific: SESSION_DRIVER is redis in
 * this app's real dev/prod config (database in CI/tests — see .env), and
 * counting "how many sessions does user X have" straight out of either store
 * isn't something both drivers support uniformly. A plain row per login,
 * inserted at session login and deleted at that session's logout, works
 * identically regardless of driver — same reasoning as the column this
 * replaces.
 *
 * Deliberately no expiry column and no scheduled cleanup: matching the
 * boolean's original design, a session slot is freed only by an explicit
 * logout or a School Admin's force-logout (UserController::forceLogout()),
 * never by time — "you must log out first" is meant literally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_login_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_login_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('session_login_active')->default(false)->after('status');
        });

        Schema::dropIfExists('user_login_sessions');
    }
};

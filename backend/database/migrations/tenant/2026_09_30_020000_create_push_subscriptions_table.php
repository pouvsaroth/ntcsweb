<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One browser/device a user has turned phone notifications on for (Web
 * Push) — see PushSubscription. A user can have several (phone + laptop).
 * `endpoint` is the push service URL the browser handed out and can run
 * past a normal index's size limit, so uniqueness is enforced on its
 * SHA-256 instead. Lives beside user_notifications in the school's own
 * database; `user_id` is a plain bigint, same reasoning as that table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('user_agent', 500)->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};

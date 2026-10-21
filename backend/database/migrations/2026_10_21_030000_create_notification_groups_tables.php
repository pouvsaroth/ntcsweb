<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram notification groups (docs/backoffice/stage-11-telegram-notifications.md):
 * a school groups its users; each group can be linked to one Telegram group,
 * and every bell notification of a member is also sent there. Central, next
 * to `users`, so deleting a school removes them (cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('telegram_chat_id', 50)->nullable();
            $table->string('telegram_title')->nullable();
            $table->timestamp('linked_at')->nullable();
            // "/link CODE" sent in the Telegram group links it; valid 30 minutes.
            $table->string('link_code', 12)->nullable()->unique();
            $table->timestamp('link_code_expires_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('notification_group_user', function (Blueprint $table) {
            $table->foreignId('notification_group_id')->constrained('notification_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['notification_group_id', 'user_id']);
            $table->index('user_id');
        });

        // Where reading the bot's updates got to (getUpdates offset).
        Schema::table('bo_support_settings', function (Blueprint $table) {
            $table->bigInteger('telegram_update_offset')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bo_support_settings', function (Blueprint $table) {
            $table->dropColumn('telegram_update_offset');
        });
        Schema::dropIfExists('notification_group_user');
        Schema::dropIfExists('notification_groups');
    }
};

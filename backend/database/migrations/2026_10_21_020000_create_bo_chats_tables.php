<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support chat (docs/backoffice/stage-10-support-chat.md): one chat per
 * customer staff user with NewTech support, and its messages (text and
 * attachments). Plus the Telegram alert settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // As they were when the chat started (the user may be deleted later).
            $table->string('user_name');
            $table->string('user_email')->nullable();
            $table->string('status', 10)->default('open'); // open | closed
            // The customer wrote last and has no answer yet.
            $table->boolean('waiting')->default(false);
            $table->unsignedInteger('unread_staff')->default(0);
            $table->unsignedInteger('unread_user')->default(0);
            $table->foreignId('last_staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index(['status', 'waiting', 'last_message_at']);
            $table->index(['tenant_id', 'last_message_at']);
        });

        Schema::create('bo_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('bo_chats')->cascadeOnDelete();
            $table->string('sender', 10); // user | staff
            $table->foreignId('staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->text('body')->nullable();
            // [{path, name, mime, size}] on the private disk under chats/{chat}/
            $table->json('attachments')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['chat_id', 'id']);
        });

        Schema::table('bo_support_settings', function (Blueprint $table) {
            $table->text('telegram_bot_token')->nullable(); // encrypted
            $table->string('telegram_chat_id', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bo_support_settings', function (Blueprint $table) {
            $table->dropColumn(['telegram_bot_token', 'telegram_chat_id']);
        });
        Schema::dropIfExists('bo_chat_messages');
        Schema::dropIfExists('bo_chats');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets (docs/backoffice/stage-13-help-support.md, step 13b): a
 * school user's ticket to NewTech and its replies. Central, like the chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // As they were when the ticket was sent (the user may be deleted later).
            $table->string('user_name');
            $table->string('user_email')->nullable();
            $table->string('subject', 200);
            $table->string('module', 40)->nullable(); // a module code, or none
            $table->string('priority', 10)->default('normal'); // low | normal | high | urgent
            $table->string('status', 15)->default('open'); // open | in_progress | resolved | closed
            $table->foreignId('assigned_staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->unsignedInteger('unread_staff')->default(0);
            $table->unsignedInteger('unread_user')->default(0);
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_reply_at']);
            $table->index(['tenant_id', 'last_reply_at']);
            $table->index(['user_id', 'last_reply_at']);
        });

        Schema::create('bo_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('bo_tickets')->cascadeOnDelete();
            $table->string('sender', 10); // user | staff
            $table->foreignId('staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->text('body')->nullable();
            // [{path, name, mime, size}] on the private disk under tickets/{ticket}/
            $table->json('attachments')->nullable();
            // A status change shown in the thread: {"status": "resolved"}.
            $table->json('event')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'id']);
        });

        // Back office: whoever answers chats may also answer tickets.
        foreach (DB::table('bo_roles')->get(['id', 'permissions']) as $role) {
            $keys = json_decode((string) $role->permissions, true) ?: [];
            if (in_array('platform.support.chat', $keys, true) && ! in_array('platform.support.tickets', $keys, true)) {
                DB::table('bo_roles')->where('id', $role->id)->update(['permissions' => json_encode([...$keys, 'platform.support.tickets'])]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_ticket_messages');
        Schema::dropIfExists('bo_tickets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-user in-app notification (bell icon in the admin header) — e.g. "a
 * student submitted a permission request" to every approver, or "your
 * request was approved" to the student/teacher/staff once it's decided.
 * See NotificationService/LeaveRequestService.
 *
 * `type` + `data` (not pre-rendered text) so the same row displays correctly
 * in whichever locale the *reader* is using at view time, not whichever
 * locale was active when it was created — see frontend's notifications.ts,
 * which looks up `notifications.types.{type}` and interpolates `data`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            // No DB-level foreign key — see leave_requests' own migration
            // docblock: `users` lives in the central database, and a
            // cross-database foreign key isn't possible in Postgres anyway.
            $table->unsignedBigInteger('recipient_id');
            $table->string('type', 60);
            $table->json('data');
            /** A frontend route path to jump to when clicked — e.g. the admin Approvals queue. */
            $table->string('link', 255)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The bell's own query shape: this recipient's unread ones, newest first.
            $table->index(['recipient_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval Flow → Groups: named groups of users (e.g. "Academic Managers",
 * "Finance Team") that an approval flow can later name as one step instead
 * of listing people one by one.
 *
 * `approval_group_members.user_id` stays a plain bigint with no foreign key,
 * same as approval_requests.decided_by — `users` lives in the central
 * database. Deleting a group cascades to its members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('approval_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_group_id')->constrained('approval_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['approval_group_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_group_members');
        Schema::dropIfExists('approval_groups');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval Flow → Flow Setting: for each approvable item (see
 * App\Support\Approvals\DocumentType), the ordered approval groups it goes
 * through. Any one member of a step's group approves that step; the item is
 * approved once its last step is. An item with no steps keeps the plain
 * permission rule (anyone with its approve permission decides).
 *
 * `approval_step_approvals` records who approved which step of which
 * request — `approvable_type` is the DocumentType string, not a model
 * class. `user_id` stays a plain bigint with no foreign key, same as
 * approval_requests.decided_by — `users` lives in the central database.
 * A group used by a flow can't be deleted (restrict).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40);
            $table->unsignedSmallInteger('step_order');
            $table->foreignId('approval_group_id')->constrained('approval_groups')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['document_type', 'step_order']);
        });

        Schema::create('approval_step_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('approvable_type', 40);
            $table->unsignedBigInteger('approvable_id');
            $table->unsignedSmallInteger('step_order');
            $table->unsignedBigInteger('approval_group_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(['approvable_type', 'approvable_id', 'step_order']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_step_approvals');
        Schema::dropIfExists('approval_flow_steps');
    }
};

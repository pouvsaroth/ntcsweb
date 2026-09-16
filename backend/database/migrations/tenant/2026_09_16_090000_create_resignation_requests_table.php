<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff member's own self-submitted resignation request — same
 * pending/approved/rejected workflow as leave_requests (see that
 * migration's docblock), but staff-only, so `staff_id` is required rather
 * than nullable. No DB-level foreign key on `staff_id`/`decided_by`, same
 * style as leave_requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resignation_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('staff_id');
            $table->date('resignation_date');
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected

            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('staff_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resignation_requests');
    }
};

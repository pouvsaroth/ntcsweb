<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Try the demo" from the website (docs/backoffice/stage-12-demo.md): who
 * tried it, as which role, and the demo account made for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_demo_visits', function (Blueprint $table) {
            $table->id();
            // The visitor's handle for "Continue my demo"; never the id.
            $table->uuid('token')->unique();
            $table->string('name');
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('role', 20); // director | teacher | student
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // The visitor's record inside the demo school's own database.
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('student_id')->nullable();
            // One-time sign-in ticket (hashed), short-lived.
            $table->string('ticket_hash', 64)->nullable()->unique();
            $table->timestamp('ticket_expires_at')->nullable();
            $table->unsignedInteger('sign_ins')->default(0);
            $table->timestamp('last_sign_in_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['ended_at', 'expires_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_demo_visits');
    }
};

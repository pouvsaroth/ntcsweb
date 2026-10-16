<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The back office's own accounts — see docs/backoffice/stage-1-foundation.md.
 *
 * Platform staff are deliberately not rows in `users`: a school user can
 * never be promoted into one, and a staff account can never sign in to a
 * school. All four tables live in the central database, never in a school's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_staff', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('status', 20)->default('active');
            $table->boolean('must_change_password')->default(true);
            // Encrypted at rest (model casts). The secret is written when
            // enrolment starts and only counts once confirmed_at is set.
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            // The TOTP time step last accepted — a code at or before it is a
            // replay, even inside its 30-second window.
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('bo_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            // The built-in Owner role: holds every permission, cannot be
            // edited or deleted.
            $table->boolean('is_owner')->default(false);
            $table->json('permissions');
            $table->timestamps();
        });

        Schema::create('bo_role_staff', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('bo_roles')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('bo_staff')->cascadeOnDelete();
            $table->primary(['role_id', 'staff_id']);
        });

        // Append-only. No updated_at: a row is never changed after it lands.
        Schema::create('bo_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->string('action', 100);
            $table->string('target_type', 100)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            // Which school the action touched, when it touched one.
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['staff_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });

        DB::table('bo_roles')->insert([
            'name' => 'Owner',
            'description' => 'Every permission. Cannot be changed or removed.',
            'is_owner' => true,
            'permissions' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_audit_logs');
        Schema::dropIfExists('bo_role_staff');
        Schema::dropIfExists('bo_roles');
        Schema::dropIfExists('bo_staff');
    }
};

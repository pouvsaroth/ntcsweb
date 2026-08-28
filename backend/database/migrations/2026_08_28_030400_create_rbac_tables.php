<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based access control.
 *
 *   permissions      platform-wide  — the fixed catalog of things that can be done.
 *   roles            tenant-owned   — NULL tenant_id means a platform-level role
 *                                     (currently only "super-admin").
 *   permission_role  join
 *   role_user        join
 *
 * Roles are per-tenant on purpose: ABC School can rename "Teacher" or add a
 * "Librarian" role without any effect on NewTech. Permissions are not, because
 * a permission slug is a hard-coded capability of the codebase, not user data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();

            // e.g. "students.create" — referenced from policies and Gate checks.
            $table->string('slug', 100)->unique();
            $table->string('name');
            $table->string('group', 50)->index(); // Groups the admin UI checkbox list.
            $table->string('description')->nullable();

            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->string('slug', 100);
            $table->string('name');
            $table->string('description')->nullable();

            // Hierarchy guard: a user may never assign or edit a role whose level
            // is >= their own highest level. super-admin 100 ... student 10.
            $table->unsignedSmallInteger('level')->default(0);

            // System roles are seeded per tenant and cannot be deleted or renamed
            // in a way that breaks the seeder's assumptions.
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'level']);
        });

        // Same NULL-tenant problem as users.email: platform roles must not collide.
        DB::statement(
            'CREATE UNIQUE INDEX roles_slug_platform_unique ON roles (slug) WHERE tenant_id IS NULL'
        );

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            $table->primary(['user_id', 'role_id']);

            // "who holds this role?" — used by the admin role detail screen.
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};

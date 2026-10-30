<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Help & Support, 13a (docs/backoffice/stage-13-help-support.md): NewTech's
 * help articles and FAQ (Khmer and English), and the contacts every school
 * sees. The school-side permission `help-support.view` goes onto every
 * school's School Admin, Teacher and Staff roles (Permissions::
 * defaultsForSystemRoles()), and the back office one onto every back office
 * role that already answers chats. Only additive.
 */
return new class extends Migration
{
    private const TENANT_ROLES = ['school-admin', 'teacher', 'staff'];

    public function up(): void
    {
        Schema::create('bo_help_articles', function (Blueprint $table) {
            $table->id();
            // A console module code (Modules::catalog()), or null = general.
            $table->string('module', 50)->nullable();
            $table->string('title_en', 200)->nullable();
            $table->string('title_km', 200)->nullable();
            $table->text('body_en')->nullable();
            $table->text('body_km')->nullable();
            $table->string('status', 10)->default('draft'); // draft | published
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'module', 'sort_order']);
        });

        Schema::create('bo_help_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('module', 50)->nullable();
            $table->string('question_en', 300)->nullable();
            $table->string('question_km', 300)->nullable();
            $table->text('answer_en')->nullable();
            $table->text('answer_km')->nullable();
            $table->string('status', 10)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('bo_staff')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });

        Schema::table('bo_support_settings', function (Blueprint $table) {
            $table->string('help_phone', 100)->nullable();
            $table->string('help_telegram', 200)->nullable();
            $table->string('help_email', 200)->nullable();
            $table->string('help_hours_en', 300)->nullable();
            $table->string('help_hours_km', 300)->nullable();
        });

        // School side: the role editor's new row, on by default for staff roles.
        app(PermissionRegistry::class)->sync();
        $permissionId = DB::table('permissions')->where('slug', 'help-support.view')->value('id');
        if ($permissionId !== null) {
            $rows = DB::table('roles')->whereNotNull('tenant_id')->whereIn('slug', self::TENANT_ROLES)->pluck('id')
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->all();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('permission_role')->insertOrIgnore($chunk);
            }
        }

        // Back office: whoever answers chats may also write help.
        foreach (DB::table('bo_roles')->get(['id', 'permissions']) as $role) {
            $keys = json_decode((string) $role->permissions, true) ?: [];
            if (in_array('platform.support.chat', $keys, true) && ! in_array('platform.support.help', $keys, true)) {
                DB::table('bo_roles')->where('id', $role->id)->update(['permissions' => json_encode([...$keys, 'platform.support.help'])]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'help-support.view')->value('id');
        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        }
        Schema::table('bo_support_settings', function (Blueprint $table) {
            $table->dropColumn(['help_phone', 'help_telegram', 'help_email', 'help_hours_en', 'help_hours_km']);
        });
        Schema::dropIfExists('bo_help_faqs');
        Schema::dropIfExists('bo_help_articles');
    }
};

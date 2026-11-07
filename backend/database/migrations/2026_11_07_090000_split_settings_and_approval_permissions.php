<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settings, eApprovals, Approval Flow and Help & Support get View / Add /
 * Update / Delete permissions of their own (they shared tenant-settings.*
 * or one "manage" permission). Every role keeps what it could do: a role
 * holding an old permission is given each new one made from it. The old
 * "manage" slugs leave the catalog, so the roles are read before sync()
 * drops them. Also adds the Help & Support tabs' Show marks (TabVisibility).
 */
return new class extends Migration
{
    /** new slug => the old slugs it comes from (any one is enough) */
    private const FROM = [
        'school-documents.view' => ['tenant-settings.view'],
        'school-documents.update' => ['tenant-settings.update'],

        'telegram-groups.view' => ['tenant-settings.update', 'telegram-groups.manage'],
        'telegram-groups.create' => ['tenant-settings.update', 'telegram-groups.manage'],
        'telegram-groups.update' => ['tenant-settings.update', 'telegram-groups.manage'],
        'telegram-groups.delete' => ['tenant-settings.update', 'telegram-groups.manage'],

        'languages.view' => ['base-data.manage-languages'],
        'languages.create' => ['base-data.manage-languages'],
        'languages.update' => ['base-data.manage-languages'],
        'languages.delete' => ['base-data.manage-languages'],

        'form-categories.view' => ['form-categories.manage'],
        'form-categories.create' => ['form-categories.manage'],
        'form-categories.update' => ['form-categories.manage'],
        'form-categories.delete' => ['form-categories.manage'],

        'form-templates.view' => ['form-templates.manage'],
        'form-templates.create' => ['form-templates.manage'],
        'form-templates.update' => ['form-templates.manage'],
        'form-templates.delete' => ['form-templates.manage'],

        'approval-groups.view' => ['approval-groups.manage'],
        'approval-groups.create' => ['approval-groups.manage'],
        'approval-groups.update' => ['approval-groups.manage'],
        'approval-groups.delete' => ['approval-groups.manage'],
        'approval-flows.view' => ['approval-groups.manage'],
        'approval-flows.update' => ['approval-groups.manage'],

        'help-tickets.create' => ['help-support.view'],
    ];

    public function up(): void
    {
        // Who holds each old slug, read before sync() removes the retired ones.
        $old = array_values(array_unique(array_merge(...array_values(self::FROM))));
        $holders = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permissions.slug', $old)
            ->get(['permissions.slug', 'permission_role.role_id'])
            ->groupBy('slug')
            ->map(fn ($rows) => $rows->pluck('role_id')->all());

        app(PermissionRegistry::class)->sync();

        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::FROM))->pluck('id', 'slug');

        $rows = [];
        foreach (self::FROM as $new => $sources) {
            if (! isset($ids[$new])) {
                continue;
            }
            foreach ($sources as $source) {
                foreach ($holders->get($source, []) as $roleId) {
                    $rows[$roleId.':'.$ids[$new]] = ['role_id' => $roleId, 'permission_id' => $ids[$new]];
                }
            }
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('permission_role')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        // sync() drops permissions that leave the catalog; nothing to undo by hand.
    }
};

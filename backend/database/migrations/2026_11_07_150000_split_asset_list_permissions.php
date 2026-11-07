<?php

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles step 4b: Asset categories, Asset locations and Repair shops get
 * their own View / Add / Update / Delete instead of the Assets ones. Each new
 * permission goes to every role that could do the same thing before.
 */
return new class extends Migration
{
    /** New permission => the old one that allowed it. */
    private const GRANTS = [
        'asset-categories.view' => 'assets.view',
        'asset-categories.create' => 'assets.create',
        'asset-categories.update' => 'assets.update',
        'asset-categories.delete' => 'assets.delete',
        'asset-locations.view' => 'assets.view',
        'asset-locations.create' => 'assets.create',
        'asset-locations.update' => 'assets.update',
        'asset-locations.delete' => 'assets.delete',
        'repair-shops.view' => 'assets.repair.view',
        'repair-shops.create' => 'assets.create',
        'repair-shops.update' => 'assets.update',
        'repair-shops.delete' => 'assets.delete',
    ];

    public function up(): void
    {
        app(PermissionRegistry::class)->sync();

        $ids = DB::table('permissions')->whereIn('slug', array_merge(array_keys(self::GRANTS), array_values(self::GRANTS)))->pluck('id', 'slug');
        foreach (self::GRANTS as $new => $old) {
            if (! isset($ids[$new], $ids[$old])) {
                continue;
            }
            $rows = DB::table('permission_role')->where('permission_id', $ids[$old])->pluck('role_id')
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $ids[$new]])->all();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('permission_role')->insertOrIgnore($chunk);
            }
        }
    }

    public function down(): void
    {
        // sync() drops permissions that leave the catalog; nothing to undo by hand.
    }
};

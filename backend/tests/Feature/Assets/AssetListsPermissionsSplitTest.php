<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Roles step 4b: Asset categories, Asset locations and Repair shops have
 * their own View / Add / Update / Delete instead of the Assets ones.
 */
class AssetListsPermissionsSplitTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_assets_create_no_longer_adds_to_the_lists(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_VIEW, Permissions::ASSETS_CREATE]);
        // The asset form still picks from them.
        $this->getJson('/api/v1/asset-categories')->assertOk();
        $this->getJson('/api/v1/asset-locations')->assertOk();
        $this->postJson('/api/v1/asset-categories', ['code' => 'CMP', 'name' => 'Computers'])->assertForbidden();
        $this->postJson('/api/v1/asset-locations', ['code' => 'LAB1', 'name' => 'Lab 1'])->assertForbidden();
        $this->postJson('/api/v1/repair-shops', ['name' => 'Fix It'])->assertForbidden();
    }

    public function test_each_list_has_its_own_add(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSET_CATEGORIES_CREATE, Permissions::REPAIR_SHOPS_CREATE]);
        $this->postJson('/api/v1/asset-categories', ['code' => 'CMP', 'name' => 'Computers'])->assertCreated();
        $this->postJson('/api/v1/repair-shops', ['name' => 'Fix It'])->assertCreated();
        $this->postJson('/api/v1/asset-locations', ['code' => 'LAB1', 'name' => 'Lab 1'])->assertForbidden();
    }

    public function test_school_admin_has_every_new_permission_by_default(): void
    {
        $defaults = Permissions::defaultsForSystemRoles()['school-admin'];
        foreach ([Permissions::ASSET_CATEGORIES_DELETE, Permissions::ASSET_LOCATIONS_DELETE, Permissions::REPAIR_SHOPS_DELETE, Permissions::REPAIR_SHOPS_VIEW] as $slug) {
            $this->assertContains($slug, $defaults);
        }
    }
}

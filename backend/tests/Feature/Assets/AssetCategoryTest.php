<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\AssetCategory;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class AssetCategoryTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_an_admin_can_create_a_category_with_a_parent(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_VIEW]);

        $parent = AssetCategory::factory()->forTenant($this->tenant)->create(['code' => 'IT', 'name' => 'IT Equipment']);

        $response = $this->postJson('/api/v1/asset-categories', [
            'code' => 'CMP',
            'name' => 'Computers',
            'parent_id' => $parent->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.parent_id', $parent->id);
    }

    public function test_a_category_code_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE]);
        AssetCategory::factory()->forTenant($this->tenant)->create(['code' => 'CMP']);

        $this->postJson('/api/v1/asset-categories', ['code' => 'CMP', 'name' => 'Duplicate'])->assertUnprocessable();
    }

    public function test_a_category_with_assets_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_DELETE]);
        $category = AssetCategory::factory()->forTenant($this->tenant)->create();
        \App\Models\Asset::factory()->forTenant($this->tenant)->forCategory($category)->create();

        $this->deleteJson("/api/v1/asset-categories/{$category->id}")->assertUnprocessable();
    }

    public function test_creating_a_category_requires_the_assets_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->postJson('/api/v1/asset-categories', ['code' => 'CMP', 'name' => 'Computers'])->assertForbidden();
    }
}

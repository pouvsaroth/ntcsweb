<?php

declare(strict_types=1);

namespace Tests\Feature\BaseData;

use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class LookupCategoryTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_a_category(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE]);

        $response = $this->postJson('/api/v1/lookup-categories', [
            'code' => 'CUSTOM_CATEGORY', 'name' => 'Custom Category',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'CUSTOM_CATEGORY');
    }

    public function test_a_duplicate_category_code_within_the_same_tenant_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE]);
        LookupCategory::factory()->create(['code' => 'GENDER']);

        $this->postJson('/api/v1/lookup-categories', ['code' => 'GENDER', 'name' => 'Gender Again'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_the_same_category_code_is_allowed_in_a_different_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE]);
        $this->createForOtherTenant(fn () => LookupCategory::factory()->forTenant(\App\Models\Tenant::factory()->create())->create(['code' => 'GENDER']));

        $this->postJson('/api/v1/lookup-categories', ['code' => 'GENDER', 'name' => 'Gender'])->assertCreated();
    }

    public function test_it_updates_a_category(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_UPDATE]);
        $category = LookupCategory::factory()->create(['name' => 'Old Name']);

        $this->putJson("/api/v1/lookup-categories/{$category->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    }

    public function test_a_category_with_values_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_DELETE]);
        $category = LookupCategory::factory()->create();
        LookupValue::factory()->forCategory($category)->create();

        $this->deleteJson("/api/v1/lookup-categories/{$category->id}")->assertStatus(422);
        $this->assertNotNull($category->fresh());
    }

    public function test_an_empty_category_can_be_deactivated_instead_of_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_UPDATE]);
        $category = LookupCategory::factory()->create(['is_active' => true]);

        $this->putJson("/api/v1/lookup-categories/{$category->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_managing_categories_requires_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->postJson('/api/v1/lookup-categories', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    }
}

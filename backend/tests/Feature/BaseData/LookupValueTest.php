<?php

declare(strict_types=1);

namespace Tests\Feature\BaseData;

use App\Models\Language;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class LookupValueTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function seedLanguages(): void
    {
        Language::factory()->create(['code' => 'en', 'is_default' => true]);
        Language::factory()->create(['code' => 'km']);
    }

    public function test_it_creates_a_value_with_translations(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE]);
        $this->seedLanguages();
        $category = LookupCategory::factory()->create(['code' => 'GENDER']);

        $response = $this->postJson('/api/v1/lookup-values', [
            'lookup_category_id' => $category->id,
            'code' => 'male',
            'translations' => [
                'en' => ['name' => 'Male'],
                'km' => ['name' => 'ប្រុស'],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'male');
        $response->assertJsonPath('data.translations.en.name', 'Male');
        $response->assertJsonPath('data.translations.km.name', 'ប្រុស');
    }

    public function test_the_default_languages_name_is_required_on_create(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE]);
        $this->seedLanguages();
        $category = LookupCategory::factory()->create();

        $response = $this->postJson('/api/v1/lookup-values', [
            'lookup_category_id' => $category->id,
            'code' => 'male',
            'translations' => ['km' => ['name' => 'ប្រុស']],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('translations.en.name');
    }

    public function test_it_updates_a_value_and_its_translations(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE, Permissions::BASE_DATA_UPDATE]);
        $this->seedLanguages();
        $category = LookupCategory::factory()->create();
        $valueId = $this->postJson('/api/v1/lookup-values', [
            'lookup_category_id' => $category->id, 'code' => 'male', 'translations' => ['en' => ['name' => 'Male']],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/lookup-values/{$valueId}", ['translations' => ['en' => ['name' => 'Man']]])
            ->assertOk()
            ->assertJsonPath('data.translations.en.name', 'Man');
    }

    public function test_a_duplicate_code_within_the_same_category_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE]);
        $this->seedLanguages();
        $category = LookupCategory::factory()->create();
        LookupValue::factory()->forCategory($category)->create(['code' => 'male']);

        $this->postJson('/api/v1/lookup-values', [
            'lookup_category_id' => $category->id, 'code' => 'male', 'translations' => ['en' => ['name' => 'Male']],
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_the_same_code_is_allowed_in_a_different_category(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE]);
        $this->seedLanguages();
        $genderCategory = LookupCategory::factory()->create(['code' => 'GENDER']);
        $otherCategory = LookupCategory::factory()->create(['code' => 'OTHER_CATEGORY']);
        LookupValue::factory()->forCategory($genderCategory)->create(['code' => 'OTHER']);

        $this->postJson('/api/v1/lookup-values', [
            'lookup_category_id' => $otherCategory->id, 'code' => 'OTHER', 'translations' => ['en' => ['name' => 'Other']],
        ])->assertCreated();
    }

    public function test_an_unknown_language_code_in_translations_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE]);
        $this->seedLanguages();
        $category = LookupCategory::factory()->create();

        $this->postJson('/api/v1/lookup-values', [
            'lookup_category_id' => $category->id, 'code' => 'male',
            'translations' => ['en' => ['name' => 'Male'], 'xx' => ['name' => 'Bogus']],
        ])->assertUnprocessable()->assertJsonValidationErrors('translations.xx');
    }

    public function test_managing_values_requires_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $category = LookupCategory::factory()->create();

        $this->postJson('/api/v1/lookup-values', ['lookup_category_id' => $category->id, 'code' => 'x'])->assertForbidden();
    }
}

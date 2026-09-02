<?php

declare(strict_types=1);

namespace Tests\Feature\BaseData;

use App\Models\Language;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class LanguageTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_a_language(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_MANAGE_LANGUAGES]);

        $response = $this->postJson('/api/v1/languages', [
            'code' => 'th', 'name' => 'Thai', 'native_name' => 'ไทย',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'th');
    }

    public function test_a_duplicate_language_code_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_MANAGE_LANGUAGES]);
        Language::factory()->create(['code' => 'th']);

        $this->postJson('/api/v1/languages', ['code' => 'th', 'name' => 'Thai', 'native_name' => 'ไทย'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_it_updates_a_language(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_MANAGE_LANGUAGES]);
        $language = Language::factory()->create(['name' => 'Thai']);

        $this->putJson("/api/v1/languages/{$language->id}", ['name' => 'ThaiLang'])
            ->assertOk()
            ->assertJsonPath('data.name', 'ThaiLang');
    }

    public function test_it_activates_and_deactivates_a_language(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_MANAGE_LANGUAGES]);
        $language = Language::factory()->create(['is_active' => true]);

        $this->putJson("/api/v1/languages/{$language->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_setting_a_language_as_default_unsets_the_previous_default(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_MANAGE_LANGUAGES]);
        $first = Language::factory()->default()->create();
        $second = Language::factory()->create();

        $this->putJson("/api/v1/languages/{$second->id}", ['is_default' => true])->assertOk();

        $this->assertFalse((bool) $first->fresh()->is_default);
        $this->assertTrue((bool) $second->fresh()->is_default);
    }

    public function test_the_default_language_cannot_be_deactivated(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_MANAGE_LANGUAGES]);
        $language = Language::factory()->default()->create();

        $this->putJson("/api/v1/languages/{$language->id}", ['is_active' => false])->assertStatus(422);
        $this->assertTrue((bool) $language->fresh()->is_active);
    }

    public function test_the_default_language_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_MANAGE_LANGUAGES]);
        $language = Language::factory()->default()->create();

        $this->deleteJson("/api/v1/languages/{$language->id}")->assertStatus(422);
        $this->assertNotNull($language->fresh());
    }

    public function test_managing_languages_requires_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->postJson('/api/v1/languages', ['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt'])
            ->assertForbidden();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\TranslationOverride;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Settings > Language > Translation — see TranslationOverrideController.
 */
class TranslationOverrideTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_saving_sets_replaces_and_clears_the_schools_words(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_MANAGE_TRANSLATIONS]);
        TranslationOverride::query()->create(['locale' => 'en', 'key' => 'adminNav.items.staffList', 'value' => 'Team']);
        TranslationOverride::query()->create(['locale' => 'en', 'key' => 'common.save', 'value' => 'Keep']);

        $this->putJson('/api/v1/translation-overrides', ['changes' => [
            ['locale' => 'km', 'key' => 'adminNav.items.students', 'value' => '  សិស្សានុសិស្ស  '],
            ['locale' => 'en', 'key' => 'adminNav.items.staffList', 'value' => 'People'],
            ['locale' => 'en', 'key' => 'common.save', 'value' => ''],
        ]])
            ->assertOk()
            ->assertJsonPath('data.km', ['adminNav.items.students' => 'សិស្សានុសិស្ស'])
            ->assertJsonPath('data.en', ['adminNav.items.staffList' => 'People']);

        $this->assertSame(2, TranslationOverride::query()->count());
    }

    public function test_anyone_signed_in_reads_them_with_every_language_present(): void
    {
        $this->actingAsAdminWithPermissions([]);
        TranslationOverride::query()->create(['locale' => 'ja', 'key' => 'common.save', 'value' => '保存する']);

        $this->getJson('/api/v1/translation-overrides')
            ->assertOk()
            ->assertJsonPath('data.ja', ['common.save' => '保存する'])
            ->assertJsonPath('data.en', []);
    }

    public function test_saving_needs_the_manage_translations_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_VIEW]);

        $this->putJson('/api/v1/translation-overrides', ['changes' => [['locale' => 'en', 'key' => 'common.save', 'value' => 'X']]])
            ->assertForbidden();
    }

    public function test_only_the_apps_languages_and_dotted_keys_are_accepted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_MANAGE_TRANSLATIONS]);

        $this->putJson('/api/v1/translation-overrides', ['changes' => [
            ['locale' => 'fr', 'key' => 'common.save', 'value' => 'X'],
            ['locale' => 'en', 'key' => 'no dots or spaces', 'value' => 'X'],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['changes.0.locale', 'changes.1.key']);
    }
}

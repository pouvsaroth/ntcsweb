<?php

declare(strict_types=1);

namespace Tests\Feature\BaseData;

use App\Models\Language;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Models\LookupValueTranslation;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * The read side every module actually calls — GET /lookups/{category}?lang=.
 * Deliberately requires no Base Data permission (just a signed-in tenant
 * user), matching the geography lookups' own precedent.
 */
class LookupApiTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private Language $english;

    private Language $khmer;

    private Language $chinese;

    private LookupCategory $gender;

    private LookupValue $male;

    private LookupValue $female;

    private function setUpGenderCatalog(): void
    {
        $this->english = Language::factory()->create(['code' => 'en', 'name' => 'English', 'is_default' => true]);
        $this->khmer = Language::factory()->create(['code' => 'km', 'name' => 'Khmer']);
        $this->chinese = Language::factory()->create(['code' => 'zh', 'name' => 'Chinese']);

        $this->gender = LookupCategory::factory()->create(['code' => 'GENDER', 'name' => 'Gender']);
        $this->male = LookupValue::factory()->forCategory($this->gender)->create(['code' => 'male', 'sort_order' => 0]);
        $this->female = LookupValue::factory()->forCategory($this->gender)->create(['code' => 'female', 'sort_order' => 1]);

        LookupValueTranslation::factory()->forValue($this->male)->forLanguage($this->english)->create(['name' => 'Male']);
        LookupValueTranslation::factory()->forValue($this->male)->forLanguage($this->khmer)->create(['name' => 'ប្រុស']);
        LookupValueTranslation::factory()->forValue($this->male)->forLanguage($this->chinese)->create(['name' => '男']);

        LookupValueTranslation::factory()->forValue($this->female)->forLanguage($this->english)->create(['name' => 'Female']);
        // Deliberately no Khmer translation for "female" — this is the fallback test.
    }

    public function test_it_lists_active_categories(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpGenderCatalog();
        LookupCategory::factory()->create(['is_active' => false, 'code' => 'HIDDEN']);

        $response = $this->getJson('/api/v1/lookups');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('GENDER'));
        $this->assertFalse($codes->contains('HIDDEN'));
    }

    public function test_english_dropdown_returns_english_names(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpGenderCatalog();

        $response = $this->getJson('/api/v1/lookups/GENDER?lang=en');

        $response->assertOk();
        $response->assertJsonFragment(['code' => 'male', 'name' => 'Male']);
        $response->assertJsonFragment(['code' => 'female', 'name' => 'Female']);
    }

    public function test_khmer_dropdown_returns_khmer_names(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpGenderCatalog();

        $response = $this->getJson('/api/v1/lookups/GENDER?lang=km');

        $response->assertOk();
        $response->assertJsonFragment(['code' => 'male', 'name' => 'ប្រុស']);
    }

    public function test_chinese_dropdown_returns_chinese_names(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpGenderCatalog();

        $this->getJson('/api/v1/lookups/GENDER?lang=zh')
            ->assertOk()
            ->assertJsonFragment(['code' => 'male', 'name' => '男']);
    }

    /**
     * The critical fallback rule: a missing Khmer translation must fall back
     * to the default language (English), never render an empty label.
     */
    public function test_a_missing_translation_falls_back_to_the_default_language(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpGenderCatalog();

        $response = $this->getJson('/api/v1/lookups/GENDER?lang=km');

        $response->assertOk();
        $response->assertJsonFragment(['code' => 'male', 'name' => 'ប្រុស']);
        // "female" has no Khmer row -> falls back to English, not blank.
        $response->assertJsonFragment(['code' => 'female', 'name' => 'Female']);
    }

    public function test_an_unknown_category_returns_an_empty_list_not_an_error(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpGenderCatalog();

        $response = $this->getJson('/api/v1/lookups/NO_SUCH_CATEGORY');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_an_inactive_value_is_excluded_from_the_dropdown(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpGenderCatalog();
        $this->male->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/lookups/GENDER?lang=en');

        $codes = collect($response->json('data'))->pluck('code');
        $this->assertFalse($codes->contains('male'));
        $this->assertTrue($codes->contains('female'));
    }

    public function test_reading_the_dropdown_requires_no_base_data_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpGenderCatalog();

        $this->getJson('/api/v1/lookups/GENDER?lang=en')->assertOk();
    }

    /**
     * Proves the cache is actually invalidated on write, not just present —
     * a stale cached response would still show the old translation here.
     */
    public function test_updating_a_translation_invalidates_the_cache_immediately(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_UPDATE]);
        $this->setUpGenderCatalog();

        $this->getJson('/api/v1/lookups/GENDER?lang=en')->assertJsonFragment(['code' => 'male', 'name' => 'Male']);

        $this->putJson("/api/v1/lookup-values/{$this->male->id}", [
            'translations' => ['en' => ['name' => 'Man']],
        ])->assertOk();

        $this->getJson('/api/v1/lookups/GENDER?lang=en')->assertJsonFragment(['code' => 'male', 'name' => 'Man']);
    }
}

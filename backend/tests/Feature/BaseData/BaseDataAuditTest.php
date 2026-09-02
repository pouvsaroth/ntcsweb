<?php

declare(strict_types=1);

namespace Tests\Feature\BaseData;

use App\Models\AuditLog;
use App\Models\Language;
use App\Models\LookupCategory;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class BaseDataAuditTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_creating_a_category_is_audited(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE]);

        $this->postJson('/api/v1/lookup-categories', ['code' => 'CUSTOM', 'name' => 'Custom'])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'CREATE', 'module' => 'Base Data']);
    }

    public function test_creating_a_value_with_translations_is_audited(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE]);
        Language::factory()->create(['code' => 'en', 'is_default' => true]);
        $category = LookupCategory::factory()->create();

        $this->postJson('/api/v1/lookup-values', [
            'lookup_category_id' => $category->id, 'code' => 'male', 'translations' => ['en' => ['name' => 'Male']],
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'CREATE', 'module' => 'Base Data']);
        $log = AuditLog::where('action', AuditAction::TRANSLATION_UPDATED)->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('en', (string) $log->description);
    }

    public function test_updating_translations_records_a_translation_updated_audit_entry(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BASE_DATA_CREATE, Permissions::BASE_DATA_UPDATE]);
        Language::factory()->create(['code' => 'en', 'is_default' => true]);
        Language::factory()->create(['code' => 'km']);
        $category = LookupCategory::factory()->create();
        $valueId = $this->postJson('/api/v1/lookup-values', [
            'lookup_category_id' => $category->id, 'code' => 'male', 'translations' => ['en' => ['name' => 'Male']],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/lookup-values/{$valueId}", ['translations' => ['km' => ['name' => 'ប្រុស']]])->assertOk();

        $this->assertSame(2, AuditLog::where('action', AuditAction::TRANSLATION_UPDATED)->count());
    }
}

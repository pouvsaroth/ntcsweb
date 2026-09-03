<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\StudyMode;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class StudyModeTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_the_first_index_call_seeds_full_time_and_part_time_defaults_once(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDY_MODES_VIEW]);

        $this->getJson('/api/v1/study-modes')->assertOk();
        $this->assertSame(2, StudyMode::count());
        $this->assertSame(['FULL_TIME', 'PART_TIME'], StudyMode::orderBy('sort_order')->pluck('code')->all());

        // A second call must not seed again.
        $this->getJson('/api/v1/study-modes')->assertOk();
        $this->assertSame(2, StudyMode::count());
    }

    public function test_it_creates_a_custom_study_mode(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDY_MODES_CREATE]);

        $response = $this->postJson('/api/v1/study-modes', ['code' => 'WEEKEND', 'name' => 'Weekend']);

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'WEEKEND');
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDY_MODES_CREATE]);
        StudyMode::factory()->create(['code' => 'WEEKEND']);

        $this->postJson('/api/v1/study-modes', ['code' => 'WEEKEND', 'name' => 'Weekend'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }
}

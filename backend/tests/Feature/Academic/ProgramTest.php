<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Program;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ProgramTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_it_creates_a_program_with_an_image(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAMS_CREATE]);

        $response = $this->post('/api/v1/programs', [
            'title' => 'Computer Basic',
            'subtitle' => 'Computer & IT',
            'category' => 'Computer & IT',
            'level' => Program::LEVEL_BEGINNER,
            'duration_label' => '2 days',
            'is_featured' => true,
            'image' => UploadedFile::fake()->image('program.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Computer Basic');
        $response->assertJsonPath('data.is_featured', true);

        $program = Program::first();
        Storage::disk('public')->assertExists($program->image_path);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/programs/", $program->image_path);
    }

    public function test_it_reports_its_default_status_and_level_immediately(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAMS_CREATE]);

        $response = $this->postJson('/api/v1/programs', [
            'title' => 'Web Development',
            'category' => 'Computer & IT',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', Program::STATUS_ACTIVE);
        $response->assertJsonPath('data.level', Program::LEVEL_BEGINNER);
        $response->assertJsonPath('data.is_featured', false);
    }

    public function test_it_saves_and_exposes_a_program_fee(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAMS_CREATE]);

        $response = $this->postJson('/api/v1/programs', [
            'title' => 'Web Development',
            'category' => 'Computer & IT',
            'fee' => '49.90',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.fee', 49.9);
    }

    public function test_a_program_without_a_fee_reports_it_as_null(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAMS_CREATE]);

        $response = $this->postJson('/api/v1/programs', [
            'title' => 'Web Development',
            'category' => 'Computer & IT',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.fee', null);
    }

    public function test_a_negative_fee_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAMS_CREATE]);

        $response = $this->postJson('/api/v1/programs', [
            'title' => 'Web Development',
            'category' => 'Computer & IT',
            'fee' => '-5',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('fee');
    }

    public function test_the_public_endpoint_exposes_the_program_fee(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        Program::factory()->create(['title' => 'Priced', 'fee' => 25.5]);

        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/programs');

        $response->assertOk();
        $response->assertJsonPath('data.0.fee', 25.5);
    }

    public function test_updating_with_a_new_image_deletes_the_old_file(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAMS_UPDATE]);
        $program = Program::factory()->create(['image_path' => 'tenants/'.$this->tenant->id.'/programs/old.jpg']);
        Storage::disk('public')->put($program->image_path, 'fake-old-image');

        $response = $this->post("/api/v1/programs/{$program->id}", [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('new.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        Storage::disk('public')->assertMissing('tenants/'.$this->tenant->id.'/programs/old.jpg');
        Storage::disk('public')->assertExists($program->fresh()->image_path);
    }

    public function test_soft_deleting_keeps_the_file_but_force_deleting_removes_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAMS_DELETE]);
        $program = Program::factory()->create(['image_path' => 'tenants/'.$this->tenant->id.'/programs/p.jpg']);
        Storage::disk('public')->put($program->image_path, 'fake-content');

        $this->deleteJson("/api/v1/programs/{$program->id}")->assertNoContent();
        Storage::disk('public')->assertExists($program->image_path);

        $program->forceDelete();
        Storage::disk('public')->assertMissing($program->image_path);
    }

    public function test_a_program_from_another_tenant_cannot_be_fetched_directly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAMS_VIEW]);
        $other = $this->createForOtherTenant(fn () => Program::factory()->forTenant(Tenant::factory()->create())->create());

        $this->getJson("/api/v1/programs/{$other->id}")->assertNotFound();
    }

    public function test_the_public_endpoint_only_returns_active_programs_in_order(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        Program::factory()->create(['sort_order' => 2, 'title' => 'Second']);
        Program::factory()->inactive()->create(['sort_order' => 0, 'title' => 'Hidden']);
        Program::factory()->create(['sort_order' => 1, 'title' => 'First']);

        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/programs');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.title', 'First');
        $response->assertJsonPath('data.1.title', 'Second');
    }

    public function test_the_public_endpoint_can_filter_to_featured_programs_only(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        Program::factory()->featured()->create(['title' => 'Featured']);
        Program::factory()->create(['title' => 'Not featured']);

        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/programs?featured=1');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Featured');
    }
}

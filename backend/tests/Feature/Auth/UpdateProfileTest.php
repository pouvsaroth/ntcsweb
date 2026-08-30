<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpdateProfileTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant)->create(['name' => 'Original Name', 'phone' => '011111111']);
        $this->actingAsTenantUser($this->user);
    }

    public function test_it_updates_name_and_phone(): void
    {
        $response = $this->postJson('/api/v1/auth/me', [
            'name' => 'New Name',
            'phone' => '022222222',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
        $response->assertJsonPath('data.phone', '022222222');
        $this->assertSame('New Name', $this->user->fresh()->name);
    }

    public function test_it_uploads_an_avatar(): void
    {
        Storage::fake('public');

        $response = $this->post('/api/v1/auth/me', [
            'name' => 'Original Name',
            'phone' => '011111111',
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertNotNull($response->json('data.avatar_url'));
        Storage::disk('public')->assertExists($this->user->fresh()->avatar_path);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/avatars/", $this->user->fresh()->avatar_path);
    }

    public function test_replacing_an_avatar_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $this->user->forceFill(['avatar_path' => "tenants/{$this->tenant->id}/avatars/old.jpg"])->save();
        Storage::disk('public')->put($this->user->avatar_path, 'fake-old-avatar');

        $this->post('/api/v1/auth/me', [
            'name' => 'Original Name',
            'phone' => '011111111',
            'avatar' => UploadedFile::fake()->image('new.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        Storage::disk('public')->assertMissing("tenants/{$this->tenant->id}/avatars/old.jpg");
        Storage::disk('public')->assertExists($this->user->fresh()->avatar_path);
    }

    public function test_phone_must_be_unique_within_the_tenant(): void
    {
        User::factory()->forTenant($this->tenant)->create(['phone' => '099999999']);

        $response = $this->postJson('/api/v1/auth/me', [
            'name' => 'Original Name',
            'phone' => '099999999',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('phone');
    }

    public function test_keeping_your_own_phone_unchanged_is_allowed(): void
    {
        $response = $this->postJson('/api/v1/auth/me', [
            'name' => 'Renamed',
            'phone' => '011111111',
        ]);

        $response->assertOk();
    }

    public function test_name_and_phone_are_required(): void
    {
        $response = $this->postJson('/api/v1/auth/me', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name', 'phone']);
    }

}

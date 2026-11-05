<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Staff list > Signature: uploads only the signature, the rest of the record stays as it is. */
class StaffSignatureUploadTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_a_signature_alone_can_be_uploaded(): void
    {
        Storage::fake('public');
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW, Permissions::STAFF_UPDATE]);
        $staff = Staff::factory()->create(['first_name' => 'Dara', 'phone' => '012345678']);

        $this->post("/api/v1/staff/{$staff->id}", ['_method' => 'PUT', 'signature' => UploadedFile::fake()->image('sign.png', 200, 60)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Dara');

        $fresh = $staff->fresh();
        $this->assertNotNull($fresh->signature_path);
        Storage::disk('public')->assertExists($fresh->signature_path);
        $this->assertSame('012345678', $fresh->phone);
    }
}

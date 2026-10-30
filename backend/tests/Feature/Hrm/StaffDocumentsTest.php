<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\Staff;
use App\Models\StaffDocument;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** HRM > Staff > Documents (docs/hrm/staff-profile-tabs.md). */
class StaffDocumentsTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_upload_list_replace_download_and_delete(): void
    {
        Storage::fake('local');
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW, Permissions::STAFF_UPDATE]);
        $staff = Staff::factory()->create();
        $url = "/api/v1/staff/{$staff->id}/documents";

        $doc = $this->post($url, [
            'type' => 'id_card',
            'title' => 'National ID',
            'document_no' => '010203040',
            'issued_on' => '2020-01-10',
            'expires_on' => '2030-01-10',
            'file' => UploadedFile::fake()->create('id.pdf', 80, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.file_name', 'id.pdf')->assertJsonPath('data.expired', false)->json('data');
        $first = StaffDocument::query()->findOrFail($doc['id'])->file_path;
        Storage::disk('local')->assertExists($first);
        $this->assertStringContainsString("staff-documents/{$staff->id}/", $first);

        // A file is needed; expiry not before issue; only allowed file types.
        $this->postJson($url, ['type' => 'cv', 'title' => 'CV'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post($url, ['type' => 'cv', 'title' => 'CV', 'issued_on' => '2020-01-10', 'expires_on' => '2019-01-01', 'file' => UploadedFile::fake()->create('cv.exe', 10)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['expires_on', 'file']);

        // Details change without a file; a new file replaces the old one.
        $this->post("{$url}/{$doc['id']}", ['type' => 'id_card', 'title' => 'National ID card', 'expires_on' => '2020-06-01'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.title', 'National ID card')->assertJsonPath('data.expired', true)->assertJsonPath('data.file_name', 'id.pdf');
        $this->post("{$url}/{$doc['id']}", ['type' => 'id_card', 'title' => 'National ID card', 'file' => UploadedFile::fake()->image('id.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.file_name', 'id.jpg');
        Storage::disk('local')->assertMissing($first);

        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->get("{$url}/{$doc['id']}/file")->assertOk();

        // Another staff member's URL cannot reach this file.
        $other = Staff::factory()->create();
        $this->get("/api/v1/staff/{$other->id}/documents/{$doc['id']}/file")->assertNotFound();
        $this->deleteJson("/api/v1/staff/{$other->id}/documents/{$doc['id']}")->assertNotFound();

        $path = StaffDocument::query()->findOrFail($doc['id'])->file_path;
        $this->deleteJson("{$url}/{$doc['id']}")->assertOk();
        Storage::disk('local')->assertMissing($path);
        $this->getJson($url)->assertJsonCount(0, 'data');
    }

    public function test_viewing_needs_staff_view_and_uploading_needs_staff_update(): void
    {
        Storage::fake('local');
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW]);
        $staff = Staff::factory()->create();
        $url = "/api/v1/staff/{$staff->id}/documents";

        $this->getJson($url)->assertOk();
        $this->post($url, ['type' => 'cv', 'title' => 'CV', 'file' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAsAdminWithPermissions([]);
        $this->getJson($url)->assertForbidden();
    }
}

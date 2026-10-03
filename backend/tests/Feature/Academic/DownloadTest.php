<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\DownloadFile;
use App\Models\DownloadFolder;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * The admin Upload menu and the public Download page — see DownloadFolder
 * and PublicDownloadController.
 */
class DownloadTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_folders_go_two_levels_deep_at_most(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DOWNLOADS_CREATE]);

        $top = $this->postJson('/api/v1/download-folders', ['name' => 'Photoshop'])->assertCreated()->json('data.id');
        $sub = $this->postJson('/api/v1/download-folders', ['name' => '2026', 'parent_id' => $top])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/download-folders', ['name' => 'Too deep', 'parent_id' => $sub])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
        $this->assertSame(2, DownloadFolder::query()->count());
    }

    public function test_the_admin_listing_is_the_folder_tree_with_file_counts(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DOWNLOADS_VIEW]);
        $top = DownloadFolder::factory()->create(['name' => 'Word photo']);
        $sub = DownloadFolder::factory()->inside($top)->create(['name' => 'Covers']);
        DownloadFile::factory()->count(2)->create(['download_folder_id' => $top->id]);
        DownloadFile::factory()->create(['download_folder_id' => $sub->id]);

        $this->getJson('/api/v1/download-folders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Word photo')
            ->assertJsonPath('data.0.files_count', 2)
            ->assertJsonPath('data.0.children.0.name', 'Covers')
            ->assertJsonPath('data.0.children.0.files_count', 1);
    }

    public function test_it_uploads_a_file_into_a_folder_named_after_the_upload(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DOWNLOADS_CREATE]);
        $folder = DownloadFolder::factory()->create();

        $this->post("/api/v1/download-folders/{$folder->id}/files", [
            'file' => UploadedFile::fake()->create('Brochure 2026.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Brochure 2026')
            ->assertJsonPath('data.extension', 'pdf')
            ->assertJsonPath('data.is_image', false);

        $file = DownloadFile::query()->firstOrFail();
        Storage::disk('public')->assertExists($file->file_path);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/downloads/", $file->file_path);
    }

    public function test_files_a_browser_would_run_are_refused(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DOWNLOADS_CREATE]);
        $folder = DownloadFolder::factory()->create();

        $this->post("/api/v1/download-folders/{$folder->id}/files", [
            'file' => UploadedFile::fake()->create('page.html', 1, 'text/html'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_managing_requires_the_downloads_permissions(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $folder = DownloadFolder::factory()->create();
        $file = DownloadFile::factory()->create(['download_folder_id' => $folder->id]);

        $this->getJson('/api/v1/download-folders')->assertForbidden();
        $this->postJson('/api/v1/download-folders', ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/download-folders/{$folder->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/download-folders/{$folder->id}")->assertForbidden();
        $this->post("/api/v1/download-folders/{$folder->id}/files", ['file' => UploadedFile::fake()->create('a.pdf', 1)], ['Accept' => 'application/json'])->assertForbidden();
        $this->putJson("/api/v1/download-files/{$file->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/download-files/{$file->id}")->assertForbidden();
    }

    public function test_renaming_a_file_and_a_folder(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DOWNLOADS_UPDATE]);
        $folder = DownloadFolder::factory()->create(['name' => 'Old']);
        $file = DownloadFile::factory()->create(['download_folder_id' => $folder->id, 'name' => 'old']);

        $this->putJson("/api/v1/download-folders/{$folder->id}", ['name' => 'New', 'status' => DownloadFolder::STATUS_INACTIVE])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.status', DownloadFolder::STATUS_INACTIVE);
        $this->putJson("/api/v1/download-files/{$file->id}", ['name' => 'new'])->assertOk()->assertJsonPath('data.name', 'new');
    }

    public function test_deleting_a_folder_removes_its_sub_folders_and_files_from_storage(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DOWNLOADS_DELETE]);
        $top = DownloadFolder::factory()->create();
        $sub = DownloadFolder::factory()->inside($top)->create();
        $topFile = DownloadFile::factory()->create(['download_folder_id' => $top->id]);
        $subFile = DownloadFile::factory()->create(['download_folder_id' => $sub->id]);
        Storage::disk('public')->put($topFile->file_path, 'a');
        Storage::disk('public')->put($subFile->file_path, 'b');

        $this->deleteJson("/api/v1/download-folders/{$top->id}")->assertNoContent();

        $this->assertSame(0, DownloadFolder::query()->count());
        $this->assertSame(0, DownloadFile::query()->count());
        Storage::disk('public')->assertMissing($topFile->file_path);
        Storage::disk('public')->assertMissing($subFile->file_path);
    }

    public function test_the_public_page_lists_only_visible_folders(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        $shown = DownloadFolder::factory()->create(['name' => 'Word photo', 'sort_order' => 1]);
        DownloadFolder::factory()->inside($shown)->create(['name' => 'Covers']);
        DownloadFolder::factory()->inside($shown)->inactive()->create(['name' => 'Drafts']);
        DownloadFolder::factory()->inactive()->create(['name' => 'Hidden']);
        DownloadFile::factory()->create(['download_folder_id' => $shown->id]);

        $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/downloads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Word photo')
            ->assertJsonPath('data.0.files_count', 1)
            ->assertJsonCount(1, 'data.0.children')
            ->assertJsonPath('data.0.children.0.name', 'Covers');
    }

    public function test_the_public_folder_page_shows_its_sub_folders_and_files(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        $top = DownloadFolder::factory()->create(['name' => 'Photoshop']);
        $sub = DownloadFolder::factory()->inside($top)->create(['name' => '2026']);
        DownloadFile::factory()->create(['download_folder_id' => $sub->id, 'name' => 'Poster', 'file_path' => 'tenants/0/downloads/x.png']);

        $this->withHeader('X-Tenant', $tenant->slug)->getJson("/api/v1/public/downloads/folders/{$top->id}")
            ->assertOk()
            ->assertJsonPath('data.parent', null)
            ->assertJsonPath('data.children.0.name', '2026')
            ->assertJsonCount(0, 'data.files');

        $this->withHeader('X-Tenant', $tenant->slug)->getJson("/api/v1/public/downloads/folders/{$sub->id}")
            ->assertOk()
            ->assertJsonPath('data.parent.name', 'Photoshop')
            ->assertJsonPath('data.files.0.name', 'Poster')
            ->assertJsonPath('data.files.0.is_image', true);
    }

    public function test_a_file_downloads_as_an_attachment_with_its_name(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        $file = DownloadFile::factory()->create(['name' => 'Brochure', 'file_path' => 'tenants/0/downloads/abc.pdf']);
        Storage::disk('public')->put($file->file_path, 'pdf');

        $response = $this->withHeader('X-Tenant', $tenant->slug)->get("/api/v1/public/downloads/files/{$file->id}");

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Brochure.pdf', $response->headers->get('content-disposition'));
    }

    public function test_nothing_inside_a_hidden_folder_is_public(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        $hidden = DownloadFolder::factory()->inactive()->create();
        $sub = DownloadFolder::factory()->inside($hidden)->create();
        $file = DownloadFile::factory()->create(['download_folder_id' => $sub->id]);
        Storage::disk('public')->put($file->file_path, 'pdf');

        $this->withHeader('X-Tenant', $tenant->slug)->getJson("/api/v1/public/downloads/folders/{$hidden->id}")->assertNotFound();
        $this->withHeader('X-Tenant', $tenant->slug)->getJson("/api/v1/public/downloads/folders/{$sub->id}")->assertNotFound();
        $this->withHeader('X-Tenant', $tenant->slug)->get("/api/v1/public/downloads/files/{$file->id}")->assertNotFound();
    }
}

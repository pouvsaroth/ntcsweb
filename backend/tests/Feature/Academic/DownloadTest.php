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

    public function test_selected_files_download_together_as_one_zip(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        $folder = DownloadFolder::factory()->create(['name' => 'Word photo']);
        $a = DownloadFile::factory()->create(['download_folder_id' => $folder->id, 'name' => 'Poster', 'file_path' => 'tenants/0/downloads/a.png']);
        $b = DownloadFile::factory()->create(['download_folder_id' => $folder->id, 'name' => 'Poster', 'file_path' => 'tenants/0/downloads/b.png']);
        $skipped = DownloadFile::factory()->create(['download_folder_id' => $folder->id, 'name' => 'Brochure', 'file_path' => 'tenants/0/downloads/c.pdf']);
        $elsewhere = DownloadFile::factory()->create(['name' => 'Other', 'file_path' => 'tenants/0/downloads/d.pdf']);
        foreach ([$a, $b, $skipped, $elsewhere] as $file) {
            Storage::disk('public')->put($file->file_path, 'x');
        }

        $response = $this->withHeader('X-Tenant', $tenant->slug)
            ->get("/api/v1/public/downloads/folders/{$folder->id}/zip?files={$a->id},{$b->id},{$elsewhere->id}");

        $response->assertOk();
        $this->assertStringContainsString('word-photo.zip', $response->headers->get('content-disposition'));
        $this->assertSame(['Poster (2).png', 'Poster.png'], $this->zipEntries($response->baseResponse->getFile()->getPathname()));
    }

    public function test_leaving_out_files_zips_the_whole_folder(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        $folder = DownloadFolder::factory()->create();
        foreach (['One', 'Two', 'Three'] as $i => $name) {
            $file = DownloadFile::factory()->create(['download_folder_id' => $folder->id, 'name' => $name, 'file_path' => "tenants/0/downloads/{$i}.pdf"]);
            Storage::disk('public')->put($file->file_path, 'x');
        }

        $response = $this->withHeader('X-Tenant', $tenant->slug)->get("/api/v1/public/downloads/folders/{$folder->id}/zip");

        $response->assertOk();
        $this->assertSame(['One.pdf', 'Three.pdf', 'Two.pdf'], $this->zipEntries($response->baseResponse->getFile()->getPathname()));
    }

    public function test_a_hidden_folder_cannot_be_zipped_and_oversized_requests_are_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);
        $hidden = DownloadFolder::factory()->inactive()->create();
        DownloadFile::factory()->create(['download_folder_id' => $hidden->id]);
        $this->withHeader('X-Tenant', $tenant->slug)->get("/api/v1/public/downloads/folders/{$hidden->id}/zip")->assertNotFound();

        $big = DownloadFolder::factory()->create();
        DownloadFile::factory()->count(2)->create(['download_folder_id' => $big->id, 'size' => 200 * 1024 * 1024]);
        $this->withHeader('X-Tenant', $tenant->slug)->getJson("/api/v1/public/downloads/folders/{$big->id}/zip")->assertUnprocessable();

        $empty = DownloadFolder::factory()->create();
        $this->withHeader('X-Tenant', $tenant->slug)->getJson("/api/v1/public/downloads/folders/{$empty->id}/zip")->assertUnprocessable();
    }

    /** @return list<string> sorted entry names */
    private function zipEntries(string $path): array
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);

        return $names;
    }
}

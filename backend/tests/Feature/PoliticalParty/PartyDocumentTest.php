<?php

declare(strict_types=1);

namespace Tests\Feature\PoliticalParty;

use App\Models\PartyDocument;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Political Party > Documents: private files in folders of any depth. */
class PartyDocumentTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::PARTY_DOCUMENTS_VIEW, Permissions::PARTY_DOCUMENTS_CREATE, Permissions::PARTY_DOCUMENTS_UPDATE, Permissions::PARTY_DOCUMENTS_DELETE];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAsAdminWithPermissions(self::ALL);
    }

    private function folder(string $name, ?int $parent = null): int
    {
        return $this->postJson('/api/v1/party-document-folders', ['name' => $name, 'parent_id' => $parent])->assertCreated()->json('data.id');
    }

    private function upload(?int $folder, array $files): array
    {
        return $this->post('/api/v1/party-documents', ['folder_id' => $folder, 'files' => $files], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    public function test_folders_nest_and_files_upload_privately_and_download(): void
    {
        $year = $this->folder('2027');
        $province = $this->folder('Battambang', $year);
        $commune = $this->folder('Anlong Vil', $province);
        $this->postJson('/api/v1/party-document-folders', ['name' => 'battambang', 'parent_id' => $year])->assertUnprocessable()->assertJsonValidationErrors('name');

        $files = $this->upload($commune, [UploadedFile::fake()->image('Poster.png'), UploadedFile::fake()->create('Plan.pdf', 20, 'application/pdf')]);
        $this->assertCount(2, $files);
        $doc = PartyDocument::query()->where('name', 'Plan.pdf')->firstOrFail();
        Storage::disk('local')->assertExists($doc->file_path);
        $this->assertStringNotContainsString('public', $doc->file_path);

        // Browse: path from the top, sub-folders with counts, files.
        $this->getJson('/api/v1/party-documents')->assertOk()->assertJsonPath('data.folders.0.name', '2027')->assertJsonPath('data.folders.0.folders_count', 1);
        $this->getJson("/api/v1/party-documents?folder_id={$commune}")->assertOk()
            ->assertJsonPath('data.path.0.name', '2027')
            ->assertJsonPath('data.path.2.name', 'Anlong Vil')
            ->assertJsonCount(2, 'data.files');

        // Search finds it anywhere, with where it is.
        $this->getJson('/api/v1/party-documents?search=plan')->assertOk()
            ->assertJsonCount(1, 'data.files')
            ->assertJsonPath('data.files.0.location.1.name', 'Battambang');

        $this->get("/api/v1/party-documents/{$doc->id}/file")->assertOk()->assertHeader('content-disposition', 'attachment; filename=Plan.pdf');
        $this->get("/api/v1/party-document-folders/{$year}/zip")->assertOk()->assertHeader('content-type', 'application/zip');

        $tree = $this->getJson('/api/v1/party-documents/folder-tree')->assertOk()->json('data');
        $this->assertSame('2027 / Battambang / Anlong Vil', $tree[2]['label']);
    }

    public function test_rename_move_and_delete(): void
    {
        $a = $this->folder('A');
        $b = $this->folder('B', $a);
        $file = $this->upload($b, [UploadedFile::fake()->create('notes.docx', 5)])[0];

        // Rename keeps the extension; move to the top.
        $this->patchJson("/api/v1/party-documents/{$file['id']}", ['name' => 'Meeting notes'])->assertOk()->assertJsonPath('data.name', 'Meeting notes.docx');
        $this->patchJson("/api/v1/party-documents/{$file['id']}", ['folder_id' => null])->assertOk()->assertJsonPath('data.folder_id', null);

        // A folder can't go inside itself.
        $this->patchJson("/api/v1/party-document-folders/{$a}", ['parent_id' => $b])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson("/api/v1/party-document-folders/{$b}", ['parent_id' => null, 'name' => 'B2'])->assertOk()->assertJsonPath('data.name', 'B2');

        // Deleting a folder deletes what is inside, files on disk too.
        $inside = $this->upload($a, [UploadedFile::fake()->create('x.txt', 1)])[0];
        $path = PartyDocument::query()->findOrFail($inside['id'])->file_path;
        $this->deleteJson("/api/v1/party-document-folders/{$a}")->assertNoContent();
        $this->assertNull(PartyDocument::query()->find($inside['id']));
        Storage::disk('local')->assertMissing($path);

        $this->deleteJson("/api/v1/party-documents/{$file['id']}")->assertNoContent();
    }

    public function test_bad_files_and_permissions(): void
    {
        $this->post('/api/v1/party-documents', ['files' => [UploadedFile::fake()->create('run.exe', 1)]], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->actingAsAdminWithPermissions([Permissions::PARTY_DOCUMENTS_VIEW]);
        $this->getJson('/api/v1/party-documents')->assertOk();
        $this->postJson('/api/v1/party-document-folders', ['name' => 'X'])->assertForbidden();

        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/party-documents')->assertForbidden();
    }
}

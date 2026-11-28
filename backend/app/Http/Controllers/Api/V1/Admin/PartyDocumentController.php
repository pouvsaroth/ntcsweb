<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PartyDocument;
use App\Models\PartyDocumentFolder;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Political Party > Documents: the party's own files in folders of any
 * depth, kept private on the server (the `local` disk) — like the website's
 * Download page, but never published. Browse a folder, search every file by
 * name, upload several at once, rename / move, download one or a folder as
 * a .zip. party-documents.view / create / update / delete.
 */
final class PartyDocumentController extends Controller
{
    /** Same list as the website Download page (StoreDownloadFileRequest). */
    public const EXTENSIONS = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,psd,ai,zip,rar,7z,mp3,mp4';

    public const MAX_KB = 51200;

    public const MAX_ZIP_FILES = 500;

    public const MAX_ZIP_BYTES = 500 * 1024 * 1024;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * GET /party-documents?folder_id=&search= — a folder's sub-folders and
     * files (the top level without folder_id), or, with `search`, every
     * folder and file whose name matches, each with where it is.
     */
    public function index(Request $request): JsonResponse
    {
        $this->allow($request, Permissions::PARTY_DOCUMENTS_VIEW);
        $data = $request->validate([
            'folder_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $folders = PartyDocumentFolder::query()->whereRaw('LOWER(name) LIKE ?', [$like])->orderBy('name')->limit(100)->get();
            $files = PartyDocument::query()->whereRaw('LOWER(name) LIKE ?', [$like])->orderBy('name')->limit(300)->get();

            return ApiResponse::success([
                'folder' => null,
                'path' => [],
                'folders' => $this->folderRows($folders, withPath: true),
                'files' => $this->fileRows($files, withPath: true),
                'search' => $search,
            ]);
        }

        $folder = isset($data['folder_id']) ? PartyDocumentFolder::query()->findOrFail((int) $data['folder_id']) : null;
        $folders = PartyDocumentFolder::query()->where('parent_id', $folder?->id)->orderBy('name')->get();
        $files = PartyDocument::query()->where('folder_id', $folder?->id)->orderBy('name')->get();

        return ApiResponse::success([
            'folder' => $folder !== null ? ['id' => $folder->id, 'name' => $folder->name, 'parent_id' => $folder->parent_id] : null,
            'path' => $folder !== null ? array_map(fn (PartyDocumentFolder $f) => ['id' => $f->id, 'name' => $f->name], $folder->path()) : [],
            'folders' => $this->folderRows($folders),
            'files' => $this->fileRows($files),
            'search' => null,
        ]);
    }

    /** GET /party-documents/folder-tree — every folder, for the Move picker. */
    public function tree(Request $request): JsonResponse
    {
        $this->allow($request, Permissions::PARTY_DOCUMENTS_VIEW);
        $all = PartyDocumentFolder::query()->orderBy('name')->get(['id', 'parent_id', 'name']);
        $byParent = $all->groupBy(fn (PartyDocumentFolder $f) => (int) $f->parent_id);

        $rows = [];
        $walk = function (int $parent, int $depth, string $prefix) use (&$walk, &$rows, $byParent) {
            foreach ($byParent->get($parent, collect()) as $folder) {
                $label = $prefix === '' ? $folder->name : $prefix.' / '.$folder->name;
                $rows[] = ['id' => $folder->id, 'parent_id' => $folder->parent_id, 'name' => $folder->name, 'label' => $label, 'depth' => $depth];
                if ($depth < 50) {
                    $walk($folder->id, $depth + 1, $label);
                }
            }
        };
        $walk(0, 0, '');

        return ApiResponse::success($rows);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $this->allow($request, Permissions::PARTY_DOCUMENTS_CREATE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer'],
        ]);
        $parentId = isset($data['parent_id']) ? PartyDocumentFolder::query()->findOrFail((int) $data['parent_id'])->id : null;
        $name = $this->cleanName($data['name']);
        $this->guardFolderName($name, $parentId);

        $folder = PartyDocumentFolder::query()->create(['parent_id' => $parentId, 'name' => $name, 'created_by' => $request->user()?->getKey()]);

        return ApiResponse::created($this->folderRows(collect([$folder]))[0]);
    }

    /** Rename, or move into another folder (parent_id; null = the top). */
    public function updateFolder(Request $request, int $id): JsonResponse
    {
        $this->allow($request, Permissions::PARTY_DOCUMENTS_UPDATE);
        $folder = PartyDocumentFolder::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $parentId = $folder->parent_id;
        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'] !== null ? PartyDocumentFolder::query()->findOrFail((int) $data['parent_id'])->id : null;
            if ($parentId !== null && in_array($parentId, $folder->descendantIds(), true)) {
                throw ValidationException::withMessages(['parent_id' => __('A folder cannot go inside itself.')]);
            }
        }
        $name = array_key_exists('name', $data) ? $this->cleanName($data['name']) : $folder->name;
        $this->guardFolderName($name, $parentId, $folder->id);

        $folder->update(['name' => $name, 'parent_id' => $parentId]);

        return ApiResponse::success($this->folderRows(collect([$folder->refresh()]))[0]);
    }

    /** Deletes the folder with everything inside it, files on disk too. */
    public function destroyFolder(Request $request, int $id): JsonResponse
    {
        $this->allow($request, Permissions::PARTY_DOCUMENTS_DELETE);
        $folder = PartyDocumentFolder::query()->findOrFail($id);
        $paths = PartyDocument::query()->whereIn('folder_id', $folder->descendantIds())->pluck('file_path')->all();

        DB::connection('tenant')->transaction(fn () => $folder->delete());
        Storage::disk('local')->delete($paths);

        return ApiResponse::noContent();
    }

    /** POST /party-documents — upload several files into a folder (folder_id) or the top. */
    public function store(Request $request): JsonResponse
    {
        $this->allow($request, Permissions::PARTY_DOCUMENTS_CREATE);
        $data = $request->validate([
            'folder_id' => ['nullable', 'integer'],
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:'.self::MAX_KB, 'extensions:'.self::EXTENSIONS],
        ], [], ['files.*' => 'file']);
        $folderId = isset($data['folder_id']) ? PartyDocumentFolder::query()->findOrFail((int) $data['folder_id'])->id : null;

        $dir = $this->context->getOrFail()->storagePath('party-documents');
        $saved = [];
        foreach ($request->file('files', []) as $file) {
            /** @var UploadedFile $file */
            $path = $file->storeAs($dir, Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'), 'local');
            abort_if($path === false, 500, 'Failed to store an uploaded file.');
            $saved[] = PartyDocument::query()->create([
                'folder_id' => $folderId,
                'name' => $this->cleanName($file->getClientOriginalName()),
                'file_path' => $path,
                'mime' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'uploaded_by' => $request->user()?->getKey(),
            ]);
        }

        return ApiResponse::created($this->fileRows(collect($saved)));
    }

    /** Rename (keeps its extension when the new name has none), or move (folder_id; null = the top). */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->allow($request, Permissions::PARTY_DOCUMENTS_UPDATE);
        $document = PartyDocument::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'folder_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $changes = [];
        if (array_key_exists('name', $data)) {
            $name = $this->cleanName($data['name']);
            $extension = pathinfo($document->name, PATHINFO_EXTENSION);
            if ($extension !== '' && pathinfo($name, PATHINFO_EXTENSION) === '') {
                $name .= '.'.$extension;
            }
            $changes['name'] = $name;
        }
        if (array_key_exists('folder_id', $data)) {
            $changes['folder_id'] = $data['folder_id'] !== null ? PartyDocumentFolder::query()->findOrFail((int) $data['folder_id'])->id : null;
        }
        $document->update($changes);

        return ApiResponse::success($this->fileRows(collect([$document->refresh()]))[0]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->allow($request, Permissions::PARTY_DOCUMENTS_DELETE);
        $document = PartyDocument::query()->findOrFail($id);
        $path = $document->file_path;
        $document->delete();
        Storage::disk('local')->delete($path);

        return ApiResponse::noContent();
    }

    /** GET /party-documents/{id}/file — the file itself; `?inline=1` shows a picture or PDF in the browser. */
    public function download(Request $request, int $id): StreamedResponse
    {
        $this->allow($request, Permissions::PARTY_DOCUMENTS_VIEW);
        $document = PartyDocument::query()->findOrFail($id);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->response(
            $document->file_path,
            $document->name,
            ['Content-Type' => $document->mime ?? 'application/octet-stream'],
            $request->boolean('inline') ? 'inline' : 'attachment',
        );
    }

    /** GET /party-document-folders/{id}/zip — the folder with its sub-folders, as a .zip keeping the folder layout. */
    public function zip(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $this->allow($request, Permissions::PARTY_DOCUMENTS_VIEW);
        $folder = PartyDocumentFolder::query()->findOrFail($id);
        $ids = $folder->descendantIds();
        $folders = PartyDocumentFolder::query()->whereIn('id', $ids)->get()->keyBy('id');
        $files = PartyDocument::query()->whereIn('folder_id', $ids)->orderBy('name')->get();

        if ($files->isEmpty()) {
            return ApiResponse::error(__('No files to download.'), 422);
        }
        if ($files->count() > self::MAX_ZIP_FILES || $files->sum('size') > self::MAX_ZIP_BYTES) {
            return ApiResponse::error(__('Too many files for one download — open a smaller folder.'), 422);
        }

        // Each file's place inside the zip: its folders below the one downloaded.
        $relative = function (int $folderId) use ($folders, $folder): string {
            $parts = [];
            for ($f = $folders->get($folderId); $f !== null && $f->id !== $folder->id; $f = $folders->get((int) $f->parent_id)) {
                array_unshift($parts, $this->zipSafe($f->name));
            }

            return $parts === [] ? '' : implode('/', $parts).'/';
        };

        $disk = Storage::disk('local');
        $zipPath = tempnam(sys_get_temp_dir(), 'party-docs-');
        $zip = new ZipArchive;
        abort_unless($zip->open($zipPath, ZipArchive::OVERWRITE) === true, 500);

        $used = [];
        foreach ($files as $file) {
            if (! $disk->exists($file->file_path)) {
                continue;
            }
            $entry = $this->uniqueEntry($relative((int) $file->folder_id).$this->zipSafe($file->name), $used);
            $zip->addFile($disk->path($file->file_path), $entry);
            $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        }
        $added = $zip->numFiles;
        $zip->close();

        if ($added === 0) {
            @unlink($zipPath);

            return ApiResponse::error(__('No files to download.'), 422);
        }

        return response()->download($zipPath, ($this->zipSafe($folder->name) ?: 'documents').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $name) ?? '');

        return mb_substr($name === '' ? 'file' : $name, 0, 255);
    }

    private function guardFolderName(string $name, ?int $parentId, ?int $exceptId = null): void
    {
        $taken = PartyDocumentFolder::query()
            ->where('parent_id', $parentId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => __('There is already a folder with this name here.')]);
        }
    }

    private function zipSafe(string $name): string
    {
        return trim(str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name));
    }

    /** @param  array<string, true>  $used */
    private function uniqueEntry(string $entry, array &$used): string
    {
        $candidate = $entry;
        $dir = pathinfo($entry, PATHINFO_DIRNAME);
        $base = pathinfo($entry, PATHINFO_FILENAME);
        $ext = pathinfo($entry, PATHINFO_EXTENSION);
        for ($n = 2; isset($used[mb_strtolower($candidate)]); $n++) {
            $candidate = ($dir !== '.' ? $dir.'/' : '').$base." ({$n})".($ext !== '' ? '.'.$ext : '');
        }
        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * @param  Collection<int, PartyDocumentFolder>  $folders
     * @return list<array<string, mixed>>
     */
    private function folderRows(Collection $folders, bool $withPath = false): array
    {
        $ids = $folders->pluck('id')->all();
        $subFolders = PartyDocumentFolder::query()->whereIn('parent_id', $ids)->selectRaw('parent_id, count(*) as n')->groupBy('parent_id')->pluck('n', 'parent_id');
        $files = PartyDocument::query()->whereIn('folder_id', $ids)->selectRaw('folder_id, count(*) as n')->groupBy('folder_id')->pluck('n', 'folder_id');

        return $folders->map(fn (PartyDocumentFolder $f) => [
            'id' => $f->id,
            'parent_id' => $f->parent_id,
            'name' => $f->name,
            'folders_count' => (int) ($subFolders[$f->id] ?? 0),
            'files_count' => (int) ($files[$f->id] ?? 0),
            'updated_at' => $f->updated_at?->toIso8601String(),
            'location' => $withPath ? $this->location($f->parent_id) : null,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, PartyDocument>  $files
     * @return list<array<string, mixed>>
     */
    private function fileRows(Collection $files, bool $withPath = false): array
    {
        $users = User::query()->whereIn('id', $files->pluck('uploaded_by')->filter()->unique()->all())->pluck('name', 'id');

        return $files->map(fn (PartyDocument $d) => [
            'id' => $d->id,
            'folder_id' => $d->folder_id,
            'name' => $d->name,
            'mime' => $d->mime,
            'size' => $d->size,
            'uploaded_by' => $d->uploaded_by !== null ? ($users[$d->uploaded_by] ?? null) : null,
            'created_at' => $d->created_at?->toIso8601String(),
            'location' => $withPath ? $this->location($d->folder_id) : null,
        ])->values()->all();
    }

    /** @var array<int, array{id: int, name: string}[]> */
    private array $locations = [];

    /** @return list<array{id: int, name: string}> where a found item is: its folders, top first ([] = the top). */
    private function location(?int $folderId): array
    {
        if ($folderId === null) {
            return [];
        }

        return $this->locations[$folderId] ??= array_map(
            fn (PartyDocumentFolder $f) => ['id' => $f->id, 'name' => $f->name],
            PartyDocumentFolder::query()->find($folderId)?->path() ?? [],
        );
    }
}

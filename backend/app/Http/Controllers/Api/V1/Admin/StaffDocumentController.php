<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HRM > Staff > Documents (docs/hrm/staff-profile-tabs.md): a staff member's
 * personal / HR files. Private: stored on the `local` disk and only sent
 * through download(). Reading follows staff.view, changing staff.update.
 */
final class StaffDocumentController extends Controller
{
    private const FILE_RULES = ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(Staff $staff): JsonResponse
    {
        $this->authorize('view', $staff);

        $docs = StaffDocument::query()->where('staff_id', $staff->id)->orderByDesc('created_at')->orderByDesc('id')->get();

        return ApiResponse::success($docs->map(fn (StaffDocument $d) => $this->row($d))->values());
    }

    public function store(Request $request, Staff $staff): JsonResponse
    {
        $this->authorize('update', $staff);
        $data = $this->validated($request, true);

        $doc = StaffDocument::query()->create([
            'staff_id' => $staff->id,
            ...$this->details($data),
            ...$this->saveFile($staff, $request->file('file')),
            'uploaded_by' => $request->user()->getKey(),
        ]);

        return ApiResponse::created($this->row($doc));
    }

    /** POST, not PUT: a new file may come with it, and PHP only reads multipart on POST. */
    public function update(Request $request, Staff $staff, int $document): JsonResponse
    {
        $this->authorize('update', $staff);
        $doc = StaffDocument::query()->where('staff_id', $staff->id)->findOrFail($document);
        $data = $this->validated($request, false);

        $changes = $this->details($data);
        if ($request->hasFile('file')) {
            $old = $doc->file_path;
            $changes = [...$changes, ...$this->saveFile($staff, $request->file('file'))];
        }
        $doc->update($changes);
        if (isset($old)) {
            Storage::disk('local')->delete($old);
        }

        return ApiResponse::success($this->row($doc));
    }

    public function download(Staff $staff, int $document): StreamedResponse
    {
        $this->authorize('view', $staff);
        $doc = StaffDocument::query()->where('staff_id', $staff->id)->findOrFail($document);
        abort_unless(Storage::disk('local')->exists($doc->file_path), 404);

        $inline = str_starts_with((string) $doc->mime, 'image/') || $doc->mime === 'application/pdf';

        return Storage::disk('local')->response($doc->file_path, $doc->file_name, ['Content-Type' => $doc->mime ?? 'application/octet-stream'], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Staff $staff, int $document): JsonResponse
    {
        $this->authorize('update', $staff);
        $doc = StaffDocument::query()->where('staff_id', $staff->id)->findOrFail($document);

        Storage::disk('local')->delete($doc->file_path);
        $doc->delete();

        return ApiResponse::success(null);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $fileRequired): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(StaffDocument::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'document_no' => ['nullable', 'string', 'max:100'],
            'issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date', Rule::when(fn ($input) => filled($input['issued_on'] ?? null), ['after_or_equal:issued_on'])],
            'note' => ['nullable', 'string', 'max:500'],
            'file' => [$fileRequired ? 'required' : 'nullable', ...self::FILE_RULES],
        ], [], ['issued_on' => 'issue date', 'expires_on' => 'expiry date', 'document_no' => 'document number']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(array $data): array
    {
        $out = [];
        foreach (['type', 'title', 'document_no', 'issued_on', 'expires_on', 'note'] as $key) {
            $value = $data[$key] ?? null;
            $out[$key] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
        }

        return $out;
    }

    /** @return array{file_path: string, file_name: string, mime: string|null, size: int} */
    private function saveFile(Staff $staff, UploadedFile $file): array
    {
        $folder = $this->context->getOrFail()->storagePath('staff-documents', (string) $staff->id);

        return [
            'file_path' => $file->storeAs($folder, Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'), 'local'),
            'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(StaffDocument $d): array
    {
        return [
            'id' => $d->id,
            'type' => $d->type,
            'title' => $d->title,
            'document_no' => $d->document_no,
            'issued_on' => $d->issued_on?->toDateString(),
            'expires_on' => $d->expires_on?->toDateString(),
            'expired' => $d->expires_on !== null && $d->expires_on->isPast(),
            'note' => $d->note,
            'file_name' => $d->file_name,
            'mime' => $d->mime,
            'size' => $d->size,
            'uploaded_at' => $d->created_at?->toDateString(),
        ];
    }
}

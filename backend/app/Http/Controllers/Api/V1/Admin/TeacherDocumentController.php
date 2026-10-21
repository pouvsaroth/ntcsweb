<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Position;
use App\Models\Staff;
use App\Models\TeacherDocument;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HRM > Teacher > Documents (docs/hrm/teachers.md): a teacher's work files —
 * contract, certificates, ID card, CV. Private: stored on the `local` disk and
 * only sent through download() to someone who may view teachers.
 */
final class TeacherDocumentController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::TEACHERS_VIEW), 403);

        $docs = TeacherDocument::query()->with('staff')
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', $request->integer('staff_id')))
            ->orderByDesc('created_at')->get();

        return ApiResponse::success($docs->map(fn (TeacherDocument $d) => $this->row($d))->values());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::TEACHERS_MANAGE), 403);
        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')->whereIn('position_id', Position::teacherPositionIds())->whereNull('deleted_at')],
            'type' => ['required', Rule::in(TeacherDocument::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'expires_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
        ]);

        $file = $request->file('file');
        $folder = $this->context->getOrFail()->storagePath('teacher-documents', (string) $data['staff_id']);
        $path = $file->storeAs($folder, Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'), 'local');

        $doc = TeacherDocument::query()->create([
            'staff_id' => $data['staff_id'],
            'type' => $data['type'],
            'title' => $data['title'],
            'expires_on' => $data['expires_on'] ?? null,
            'note' => $data['note'] ?? null,
            'file_path' => $path,
            'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $request->user()->getKey(),
        ]);

        return ApiResponse::created($this->row($doc->load('staff')));
    }

    public function download(Request $request, TeacherDocument $document): StreamedResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::TEACHERS_VIEW), 403);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        $inline = str_starts_with((string) $document->mime, 'image/') || $document->mime === 'application/pdf';

        return Storage::disk('local')->response($document->file_path, $document->file_name, ['Content-Type' => $document->mime ?? 'application/octet-stream'], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, TeacherDocument $document): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::TEACHERS_MANAGE), 403);

        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function row(TeacherDocument $d): array
    {
        /** @var Staff|null $staff */
        $staff = $d->staff;

        return [
            'id' => $d->id,
            'staff_id' => $d->staff_id,
            'teacher' => $staff?->fullName(),
            'type' => $d->type,
            'title' => $d->title,
            'file_name' => $d->file_name,
            'mime' => $d->mime,
            'size' => $d->size,
            'expires_on' => $d->expires_on?->toDateString(),
            'expired' => $d->expires_on !== null && $d->expires_on->isPast(),
            'note' => $d->note,
            'uploaded_at' => $d->created_at?->toDateString(),
        ];
    }
}

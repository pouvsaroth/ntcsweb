<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingParticipantResource;
use App\Http\Responses\ApiResponse;
use App\Models\TrainingParticipant;
use App\Services\ProfessionalDevelopment\TrainingCertificates;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HRM > Professional Development > Training Records and Certificates
 * (docs/hrm/professional-development.md): every training each staff member
 * was on, with attendance and result, and the certificates issued for them.
 */
final class TrainingRecordController extends Controller
{
    public function __construct(private readonly TrainingCertificates $certificates) {}

    /** Training history: one row per person per training, newest first. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $rows = $this->filtered($request)
            ->with(['staff', 'training'])
            ->orderByDesc(TrainingRecordController::trainingStart())
            ->orderBy('training_participants.id')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return ApiResponse::success(TrainingParticipantResource::collection($rows));
    }

    /** Totals for the same filters: trainings, attended, hours attended, certificates. */
    public function totals(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $rows = $this->filtered($request)->with('training:id,hours,status')->get(['id', 'training_id', 'attendance', 'certificate_issued_on']);
        $attended = $rows->where('attendance', 'attended');

        return ApiResponse::success([
            'trainings' => $rows->count(),
            'attended' => $attended->count(),
            'hours' => round((float) $attended->sum(fn (TrainingParticipant $p) => (float) ($p->training?->hours ?? 0)), 2),
            'certificates' => $rows->whereNotNull('certificate_issued_on')->count(),
        ]);
    }

    /**
     * Certificates: ?state=issued (default) lists certificates given;
     * ?state=pending lists people who attended a completed training, did not
     * fail it, and have none yet.
     */
    public function certificates(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $query = $this->filtered($request)->with(['staff', 'training']);
        if ($request->query('state') === 'pending') {
            $query->whereNull('certificate_issued_on')
                ->where('attendance', 'attended')
                ->where(fn ($q) => $q->whereNull('result')->orWhere('result', 'passed'))
                ->whereHas('training', fn ($q) => $q->where('status', 'completed'));
            $query->orderByDesc(TrainingRecordController::trainingStart());
        } else {
            $query->whereNotNull('certificate_issued_on');
            if ($request->query('state') === 'expiring') {
                $query->whereNotNull('certificate_expires_on')->where('certificate_expires_on', '<=', now()->addDays(60)->toDateString());
            }
            $query->orderByDesc('certificate_issued_on');
        }
        $rows = $query->orderBy('training_participants.id')->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return ApiResponse::success(TrainingParticipantResource::collection($rows));
    }

    /** Issue (or edit) a certificate; the number is made up when left blank; a file is optional. */
    public function issueCertificate(Request $request, TrainingParticipant $participant): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'certificate_no' => ['nullable', 'string', 'max:100'],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        $this->certificates->issue($participant, $data, $request->file('file'));

        return ApiResponse::success(new TrainingParticipantResource($participant->refresh()->load(['staff', 'training'])));
    }

    public function downloadCertificate(Request $request, TrainingParticipant $participant): StreamedResponse
    {
        $this->authorizeView($request);
        $path = $participant->certificate_file_path;
        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        $mime = $participant->certificate_mime ?? 'application/octet-stream';
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

        return Storage::disk('local')->response($path, $participant->certificate_file_name, ['Content-Type' => $mime], $inline ? 'inline' : 'attachment');
    }

    public function revokeCertificate(Request $request, TrainingParticipant $participant): JsonResponse
    {
        $this->authorizeManage($request);

        $this->certificates->revoke($participant);

        return ApiResponse::noContent();
    }

    /**
     * Shared filters: staff_id, year (of the training), attendance, and
     * search over the training title or the person's name / code.
     *
     * @return Builder<TrainingParticipant>
     */
    private function filtered(Request $request): Builder
    {
        $query = TrainingParticipant::query()->select('training_participants.*');

        if ($request->filled('staff_id')) {
            $query->where('staff_id', $request->integer('staff_id'));
        }
        if ($request->filled('year')) {
            $query->whereHas('training', fn ($q) => $q->whereYear('start_date', $request->integer('year')));
        }
        if ($request->filled('attendance')) {
            $query->where('attendance', (string) $request->query('attendance'));
        }
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q
                ->whereHas('training', fn ($t) => $t->where('title', 'ilike', $like)->orWhere('category', 'ilike', $like))
                ->orWhereHas('staff', fn ($s) => $s->where('first_name', 'ilike', $like)->orWhere('last_name', 'ilike', $like)->orWhere('employee_code', 'ilike', $like))
                ->orWhere('certificate_no', 'ilike', $like));
        }

        return $query;
    }

    /** Sort key: the training's start date. */
    private static function trainingStart(): \Illuminate\Database\Query\Builder
    {
        return \App\Models\Training::query()->select('start_date')
            ->whereColumn('trainings.id', 'training_participants.training_id')->toBase();
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasPermission(Permissions::PROFESSIONAL_DEVELOPMENT_VIEW), 403);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasPermission(Permissions::PROFESSIONAL_DEVELOPMENT_MANAGE), 403);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingResource;
use App\Http\Responses\ApiResponse;
use App\Models\Position;
use App\Models\Staff;
use App\Models\Training;
use App\Models\TrainingParticipant;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\ProfessionalDevelopment\TrainingCertificates;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * HRM > Professional Development > Training Calendar
 * (docs/hrm/professional-development.md): plan a training (what, who runs
 * it, where and when), assign any staff to it (they are notified), then take
 * attendance and results. A past or outside course is recorded the same way,
 * created as completed.
 */
final class TrainingController extends Controller
{
    private const LINK = '/admin/professional-development/calendar';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TrainingCertificates $certificates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $query = Training::query()->withCount([
            'participants',
            'participants as attended_count' => fn ($q) => $q->where('attendance', 'attended'),
        ]);
        $today = now()->toDateString();
        $when = $request->query('when');
        if ($when === 'upcoming') {
            $query->where('end_date', '>=', $today)->where('status', 'planned');
        } elseif ($when === 'past') {
            $query->where(fn ($q) => $q->where('end_date', '<', $today)->orWhere('status', '!=', 'planned'));
        }
        if ($request->filled('year')) {
            $query->whereYear('start_date', $request->integer('year'));
        }

        if (! $request->filled('sort')) {
            $request->merge(['sort' => $when === 'upcoming' ? 'start_date' : '-start_date']);
        }
        $trainings = ApiQuery::for($query, $request)
            ->searchable('title', 'category', 'trainer')
            ->filterable(['status', 'provider_type', 'category'])
            ->sortable(['start_date', 'title', 'created_at'])
            ->paginate();

        return ApiResponse::success(TrainingResource::collection($trainings));
    }

    /** Every training touching a month (?month=YYYY-MM), for the calendar grid. */
    public function calendar(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        $first = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01')->startOfDay();
        // Whole weeks around the month, as the grid shows them.
        $from = $first->startOfWeek()->toDateString();
        $to = $first->endOfMonth()->endOfWeek()->toDateString();

        $trainings = Training::query()->withCount('participants')
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->orderBy('start_date')->orderBy('start_time')->get();

        return ApiResponse::success(TrainingResource::collection($trainings));
    }

    /** Counts for the cards above the list. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $today = now()->toDateString();
        $year = now()->year;

        return ApiResponse::success([
            'upcoming' => Training::query()->where('status', 'planned')->where('end_date', '>=', $today)->count(),
            'this_month' => Training::query()->where('status', '!=', 'cancelled')
                ->where('start_date', '<=', now()->endOfMonth()->toDateString())
                ->where('end_date', '>=', now()->startOfMonth()->toDateString())->count(),
            'completed_this_year' => Training::query()->where('status', 'completed')->whereYear('start_date', $year)->count(),
            'staff_trained_this_year' => TrainingParticipant::query()->where('attendance', 'attended')
                ->whereHas('training', fn ($q) => $q->whereYear('start_date', $year))->distinct()->count('staff_id'),
        ]);
    }

    /** Active staff to assign, flagged when in a teacher position. */
    public function staffOptions(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $teacherPositions = Position::teacherPositionIds();
        $staff = Staff::query()->active()->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code', 'position_id'])
            ->map(fn (Staff $s) => [
                'id' => $s->id,
                'name' => $s->fullName(),
                'employee_code' => $s->employee_code,
                'is_teacher' => in_array($s->position_id, $teacherPositions, true),
            ]);

        return ApiResponse::success($staff);
    }

    /** Categories used so far, for the form's suggestions. */
    public function categories(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return ApiResponse::success(Training::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $this->validated($request, true);
        $participantIds = array_map('intval', $data['participant_ids'] ?? []);
        $status = $data['status'] ?? 'planned';

        $training = DB::connection('tenant')->transaction(function () use ($request, $data, $participantIds, $status) {
            $training = Training::query()->create([...Arr::except($data, ['participant_ids']), 'status' => $status, 'created_by' => $request->user()?->id]);
            foreach ($participantIds as $staffId) {
                // A training recorded after the fact: the people on it went.
                $training->participants()->create(['staff_id' => $staffId, 'attendance' => $status === 'completed' ? 'attended' : 'registered']);
            }

            return $training;
        });

        if ($status === 'planned') {
            $this->notifyStaff($participantIds, NotificationType::TRAINING_ASSIGNED, $training, $request->user());
        }

        return ApiResponse::created(new TrainingResource($this->loaded($training)));
    }

    public function show(Request $request, Training $training): JsonResponse
    {
        $this->authorizeView($request);

        return ApiResponse::success(new TrainingResource($this->loaded($training)));
    }

    /**
     * Edit details or status, add or remove staff. Newly added staff are
     * told about a planned training; everyone on it hears when it is
     * cancelled.
     */
    public function update(Request $request, Training $training): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $this->validated($request, false);
        $before = $training->participants()->pluck('staff_id')->map(fn ($id) => (int) $id)->all();

        DB::connection('tenant')->transaction(function () use ($training, $data, $before) {
            $training->update(Arr::except($data, ['participant_ids']));
            if (array_key_exists('participant_ids', $data)) {
                $after = array_map('intval', $data['participant_ids']);
                $removed = $training->participants()->whereIn('staff_id', array_diff($before, $after))->get();
                foreach ($removed as $participant) {
                    $this->certificates->forget($participant);
                    $participant->delete();
                }
                foreach (array_diff($after, $before) as $staffId) {
                    $training->participants()->create(['staff_id' => $staffId, 'attendance' => $training->status === 'completed' ? 'attended' : 'registered']);
                }
            }
            // Certificates name the training in Qualification; keep that in step.
            if ($training->wasChanged(['title', 'trainer'])) {
                foreach ($training->participants()->whereNotNull('certificate_issued_on')->get() as $participant) {
                    $this->certificates->syncQualification($participant);
                }
            }
        });

        $after = array_key_exists('participant_ids', $data) ? array_map('intval', $data['participant_ids']) : $before;
        if ($training->status === 'planned') {
            $this->notifyStaff(array_values(array_diff($after, $before)), NotificationType::TRAINING_ASSIGNED, $training, $request->user());
        }
        if ($training->wasChanged('status') && $training->status === 'cancelled') {
            $this->notifyStaff(array_values(array_intersect($after, $before)), NotificationType::TRAINING_CANCELLED, $training, $request->user());
        }

        return ApiResponse::success(new TrainingResource($this->loaded($training)));
    }

    /**
     * Take attendance and results for several people at once:
     * rows = [{id, attendance, result?, score?, note?}].
     */
    public function attendance(Request $request, Training $training): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'rows' => ['required', 'array', 'max:500'],
            'rows.*.id' => ['required', 'integer'],
            'rows.*.attendance' => ['required', Rule::in(TrainingParticipant::ATTENDANCES)],
            'rows.*.result' => ['nullable', Rule::in(TrainingParticipant::RESULTS)],
            'rows.*.score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'rows.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::connection('tenant')->transaction(function () use ($training, $data) {
            $participants = $training->participants()->get()->keyBy('id');
            foreach ($data['rows'] as $row) {
                $participant = $participants->get((int) $row['id']);
                if ($participant === null) {
                    continue;
                }
                $participant->update([
                    'attendance' => $row['attendance'],
                    'result' => $row['result'] ?? null,
                    'score' => $row['score'] ?? null,
                    'note' => $row['note'] ?? null,
                ]);
            }
        });

        return ApiResponse::success(new TrainingResource($this->loaded($training)));
    }

    public function destroy(Request $request, Training $training): JsonResponse
    {
        $this->authorizeManage($request);

        DB::connection('tenant')->transaction(function () use ($training) {
            foreach ($training->participants()->get() as $participant) {
                $this->certificates->forget($participant);
            }
            $training->delete();
        });

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $staff = Rule::exists('tenant.staff', 'id')->whereNull('deleted_at');

        return $request->validate([
            'title' => [$required, 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'provider_type' => ['sometimes', Rule::in(Training::PROVIDER_TYPES)],
            'trainer' => ['nullable', 'string', 'max:200'],
            'mode' => ['sometimes', Rule::in(Training::MODES)],
            'location' => ['nullable', 'string', 'max:200'],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'end_date' => [$required, 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(Training::STATUSES)],
            'participant_ids' => ['sometimes', 'array', 'max:500'],
            'participant_ids.*' => ['integer', 'distinct', $staff],
        ]);
    }

    private function loaded(Training $training): Training
    {
        return $training->load(['participants' => fn ($q) => $q->with('staff')->orderBy('id')])
            ->loadCount(['participants', 'participants as attended_count' => fn ($q) => $q->where('attendance', 'attended')]);
    }

    /** @param  list<int>  $staffIds */
    private function notifyStaff(array $staffIds, string $type, Training $training, ?User $actor): void
    {
        if ($staffIds === []) {
            return;
        }

        $users = Staff::query()->whereIn('id', $staffIds)->with('user')->get()
            ->pluck('user')->filter()
            ->reject(fn (User $u) => $actor !== null && $u->getKey() === $actor->getKey());

        $this->notifications->notifyMany($users, $type, [
            'training_id' => $training->id,
            'title' => $training->title,
            'date' => $training->start_date->toDateString(),
            'location' => $training->location,
        ], link: self::LINK);
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

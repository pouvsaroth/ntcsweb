<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\InterviewRequest;
use App\Http\Resources\InterviewResource;
use App\Http\Responses\ApiResponse;
use App\Models\ApprovalGroup;
use App\Models\Interview;
use App\Models\User;
use App\Services\Recruitment\InterviewService;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM > Recruitment > Interview.
 */
final class InterviewController extends Controller
{
    private const RELATIONS = ['applicant.jobPosition', 'interviewerRows', 'evaluations'];

    public function __construct(
        private readonly InterviewService $interviews,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Interview::class);
        $user = $request->user();

        $query = Interview::query()->with(self::RELATIONS);

        match ($request->query('when')) {
            'upcoming' => $query->where('scheduled_at', '>=', now()->startOfDay()),
            'past' => $query->where('scheduled_at', '<', now()->startOfDay()),
            default => null,
        };

        // Interview evaluation's "waiting for me": interviews I sit on, not
        // cancelled, that I haven't evaluated yet.
        if ($request->boolean('awaiting_my_evaluation')) {
            $query->withInterviewer($user)
                ->whereIn('status', ['scheduled', 'completed'])
                ->whereDoesntHave('evaluations', fn (Builder $evaluation) => $evaluation->where('evaluator_id', $user->getKey()));
        }

        if ($request->filled('job_position_id')) {
            $query->whereHas('applicant', fn (Builder $applicant) => $applicant->where('job_position_id', $request->integer('job_position_id')));
        }

        $interviews = ApiQuery::for($query, $request)
            ->filterable(['status', 'applicant_id', 'mode'])
            ->sortable(['scheduled_at', 'created_at'], default: $request->query('when') === 'past' ? '-scheduled_at' : 'scheduled_at')
            ->paginate();

        return ApiResponse::success(InterviewResource::collection($interviews));
    }

    /** Who can be picked as an interviewer — working staff with an account. */
    public function interviewers(): JsonResponse
    {
        $this->authorize('viewAny', Interview::class);

        $users = User::query()
            ->inTenant($this->context->id())
            ->active()
            ->whereIn('id', ApprovalGroup::eligibleUserIds())
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);

        return ApiResponse::success($users);
    }

    public function store(InterviewRequest $request): JsonResponse
    {
        $this->authorize('create', Interview::class);

        $interview = $this->interviews->schedule(
            $request->safe()->except('interviewer_ids'),
            array_map('intval', $request->validated('interviewer_ids')),
            $request->user(),
        );

        return ApiResponse::created(new InterviewResource($interview->load(self::RELATIONS)));
    }

    public function show(Interview $interview): JsonResponse
    {
        $this->authorize('view', $interview);

        return ApiResponse::success(new InterviewResource($interview->load(self::RELATIONS)));
    }

    public function update(InterviewRequest $request, Interview $interview): JsonResponse
    {
        $this->authorize('update', $interview);

        $this->interviews->update(
            $interview,
            $request->safe()->except('interviewer_ids'),
            $request->has('interviewer_ids') ? array_map('intval', $request->validated('interviewer_ids')) : null,
        );

        return ApiResponse::success(new InterviewResource($interview->fresh(self::RELATIONS)));
    }

    public function destroy(Interview $interview): JsonResponse
    {
        $this->authorize('delete', $interview);

        $interview->delete();

        return ApiResponse::noContent();
    }
}

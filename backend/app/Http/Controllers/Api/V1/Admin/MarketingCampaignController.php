<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketingCampaignResource;
use App\Http\Responses\ApiResponse;
use App\Models\LookupValue;
use App\Models\MarketingCampaign;
use App\Support\Query\ApiQuery;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Marketing > Campaigns: each campaign with its channel, dates, budget and
 * real cost, and how many inquiries (leads) it brought and how many enrolled.
 */
final class MarketingCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MarketingCampaign::class);

        $query = $this->withCounts(MarketingCampaign::query()->with('program'));

        $term = trim((string) $request->input('search', ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'ILIKE', $like)->orWhere('code', 'ILIKE', $like)->orWhere('target', 'ILIKE', $like));
        }

        $campaigns = ApiQuery::for($query, $request)
            ->filterable(['status', 'channel', 'currency'])
            ->sortable(['code', 'name', 'start_on', 'created_at'], default: '-start_on')
            ->paginate();

        return ApiResponse::success(MarketingCampaignResource::collection($campaigns));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MarketingCampaign::class);

        $data = $request->validate($this->rules(true));
        $campaign = MarketingCampaign::query()->create([...$data, 'created_by' => $request->user()?->id]);

        return ApiResponse::created($this->full($campaign));
    }

    public function show(MarketingCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        return ApiResponse::success($this->full($campaign));
    }

    public function update(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);

        $data = $request->validate($this->rules(false));
        $start = $data['start_on'] ?? $campaign->start_on?->toDateString();
        $end = array_key_exists('end_on', $data) ? $data['end_on'] : $campaign->end_on?->toDateString();
        if ($start && $end && $end < $start) {
            throw ValidationException::withMessages(['end_on' => __('The end date must be on or after the start date.')]);
        }
        $campaign->update($data);

        return ApiResponse::success($this->full($campaign));
    }

    /** Its inquiries stay; they just lose the campaign link. */
    public function destroy(MarketingCampaign $campaign): JsonResponse
    {
        $this->authorize('delete', $campaign);

        $campaign->delete();

        return ApiResponse::noContent();
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            // A MARKETING_CHANNEL Base Data code; an inactive one still counts, so an older campaign keeps saving.
            'channel' => [$required, 'string', 'max:50', function (string $attribute, mixed $value, Closure $fail): void {
                $known = LookupValue::query()
                    ->where('code', $value)
                    ->whereHas('category', fn ($q) => $q->where('code', MarketingCampaign::CHANNEL_CATEGORY))
                    ->exists();
                if (! $known) {
                    $fail(__('Choose a channel from the list.'));
                }
            }],
            'start_on' => [$required, 'date_format:Y-m-d'],
            'end_on' => ['nullable', 'date_format:Y-m-d', ...($creating ? ['after_or_equal:start_on'] : [])],
            'currency' => [$required, Rule::in(['USD', 'KHR'])],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'spent' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'academic_program_id' => ['nullable', 'integer', Rule::exists('tenant.academic_programs', 'id')->whereNull('deleted_at')],
            'target' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(MarketingCampaign::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Leads = inquiries that picked the campaign; enrolled = those that became students.
     *
     * @param  Builder<MarketingCampaign>  $query
     * @return Builder<MarketingCampaign>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            'inquiries as leads_count',
            'inquiries as enrolled_count' => fn ($q) => $q->where(fn ($w) => $w->where('status', 'enrolled')->orWhereNotNull('student_id')),
        ]);
    }

    private function full(MarketingCampaign $campaign): MarketingCampaignResource
    {
        return new MarketingCampaignResource($this->withCounts(MarketingCampaign::query()->with('program'))->findOrFail($campaign->id));
    }
}

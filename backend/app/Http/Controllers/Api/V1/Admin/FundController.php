<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FundEntryResource;
use App\Http\Resources\FundResource;
use App\Http\Responses\ApiResponse;
use App\Models\Fund;
use App\Models\FundTransaction;
use App\Models\LookupValue;
use App\Services\Fund\FundService;
use App\Support\Query\ApiQuery;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Fund > Funds: pots of the school's money, each with a type and currency.
 * Money in / money out (date, from / paid to, remark) post to the ledger
 * through FundService; the statement lists them with the running balance.
 */
final class FundController extends Controller
{
    public function __construct(private readonly FundService $funds) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Fund::class);

        $query = $this->withTotals(Fund::query());

        $term = trim((string) $request->input('search', ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'ILIKE', $like)->orWhere('fund_no', 'ILIKE', $like));
        }

        $funds = ApiQuery::for($query, $request)
            ->filterable(['status', 'currency', 'fund_type'])
            ->sortable(['fund_no', 'name', 'opened_on', 'created_at'], default: '-opened_on')
            ->paginate();

        return ApiResponse::success(FundResource::collection($funds));
    }

    /** Active funds and their money, per currency (never summed together). */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Fund::class);

        $rows = $this->withTotals(Fund::query()->where('status', 'active'))->get();
        $summary = [];
        foreach (['USD', 'KHR'] as $currency) {
            $mine = $rows->where('currency', $currency);
            $summary[] = [
                'currency' => $currency,
                'funds' => $mine->count(),
                'balance' => round($mine->sum(fn ($f) => (float) $f->total_in - (float) $f->total_out), 2),
            ];
        }

        return ApiResponse::success($summary);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Fund::class);

        $data = $request->validate([
            ...$this->fundRules(true),
            'currency' => ['required', Rule::in(['USD', 'KHR'])],
            // Optional money already in the fund, on the start date.
            'opening_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'cash_account_id' => ['required_with:opening_amount', 'nullable', 'integer', $this->cashAccountRule()],
            'party' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $opening = isset($data['opening_amount'])
            ? ['amount' => $data['opening_amount'], 'cash_account_id' => (int) $data['cash_account_id'], 'party' => $data['party'] ?? null, 'remark' => $data['remark'] ?? null]
            : null;
        $fund = $this->funds->create(collect($data)->only(['name', 'fund_type', 'currency', 'opened_on', 'target_amount', 'description'])->all(), $opening, $request->user());

        return ApiResponse::created($this->full($fund));
    }

    public function show(Fund $fund): JsonResponse
    {
        $this->authorize('view', $fund);

        return ApiResponse::success($this->full($fund));
    }

    /** Name, type, start date, goal, description, and active / closed (closing needs a zero balance). */
    public function update(Request $request, Fund $fund): JsonResponse
    {
        $this->authorize('update', $fund);

        $data = $request->validate([
            ...$this->fundRules(false),
            'status' => ['sometimes', Rule::in(Fund::STATUSES)],
        ]);

        if (($data['status'] ?? null) === 'closed' && abs($this->funds->balance($fund)) > 0.001) {
            throw ValidationException::withMessages(['status' => __('Spend or move the whole balance before closing the fund.')]);
        }
        if (isset($data['opened_on']) && $fund->entries()->where('transacted_on', '<', $data['opened_on'])->exists()) {
            throw ValidationException::withMessages(['opened_on' => __('There are entries before this date.')]);
        }

        $fund->update($data);

        return ApiResponse::success($this->full($fund));
    }

    public function destroy(Fund $fund): JsonResponse
    {
        $this->authorize('delete', $fund);

        if ($fund->entries()->exists()) {
            throw ValidationException::withMessages(['status' => __('This fund has money in or out. Remove them first, or close the fund.')]);
        }
        $fund->delete();

        return ApiResponse::noContent();
    }

    /** Money in or money out, with its date, from / paid to, and remark. */
    public function record(Request $request, Fund $fund): JsonResponse
    {
        $this->authorize('record', $fund);

        $data = $request->validate([
            'type' => ['required', Rule::in(FundTransaction::TYPES)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'transacted_on' => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', $this->cashAccountRule()],
            'party' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = $this->funds->record($fund, $data, $request->user());

        return ApiResponse::created(new FundEntryResource($entry->load(['cashAccount', 'creator'])));
    }

    public function removeEntry(Request $request, FundTransaction $entry): JsonResponse
    {
        $fund = Fund::query()->findOrFail($entry->fund_id);
        $this->authorize('delete', $fund);

        $this->funds->remove($entry, $request->user());

        return ApiResponse::success($this->full($fund));
    }

    /** @return array<string, list<mixed>> */
    private function fundRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            // A FUND_TYPE Base Data code; an inactive one still counts, so an older fund keeps saving.
            'fund_type' => [$required, 'string', 'max:50', function (string $attribute, mixed $value, Closure $fail): void {
                $known = LookupValue::query()
                    ->where('code', $value)
                    ->whereHas('category', fn ($q) => $q->where('code', Fund::TYPE_CATEGORY))
                    ->exists();
                if (! $known) {
                    $fail(__('Choose a fund type from the list.'));
                }
            }],
            'opened_on' => [$required, 'date'],
            'target_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function full(Fund $fund): FundResource
    {
        $fresh = $this->withTotals(Fund::query())->findOrFail($fund->id);
        $resource = new FundResource($fresh);
        $resource->statement = $this->funds->statement($fresh);

        return $resource;
    }

    /**
     * @param  Builder<Fund>  $query
     * @return Builder<Fund>
     */
    private function withTotals(Builder $query): Builder
    {
        return $query
            ->withSum(['entries as total_in' => fn ($q) => $q->where('type', FundTransaction::IN)], 'amount')
            ->withSum(['entries as total_out' => fn ($q) => $q->where('type', FundTransaction::OUT)], 'amount')
            ->withCount('entries')
            ->withMax('entries as last_entry_on', 'transacted_on');
    }

    private function cashAccountRule(): Exists
    {
        return Rule::exists('tenant.accounts', 'id')->where('is_bank_or_cash', true)->where('is_active', true);
    }
}

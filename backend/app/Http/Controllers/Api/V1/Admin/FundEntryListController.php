<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FundEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Fund;
use App\Models\FundTransaction;
use App\Support\Billing\Money;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Fund > Money in & out: every money in or money out of every fund in one
 * list, newest first, narrowed by type, dates or For month, fund, fund type
 * and a search (from / paid to, remark, fund). Each amount also in USD and
 * KHR at its date's rate; meta.totals adds up everything the filters keep
 * (not only the page). Excel exports the same rows.
 */
final class FundEntryListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Fund::class);

        $page = ApiQuery::for($this->filtered($request)->with('fund:id,fund_no,name,currency,status,fund_type'), $request)->paginate();
        $page->setCollection($page->getCollection()->map(fn (FundTransaction $e) => $this->present($e, $request)));

        return ApiResponse::success($page, meta: ['totals' => $this->totals($this->filtered($request))]);
    }

    public function excel(Request $request): Response
    {
        $this->authorize('viewAny', Fund::class);

        $entries = $this->filtered($request)->with('fund:id,fund_no,name,currency')->limit(20000)->get();
        $totals = $this->totals($this->filtered($request));
        $bold = (new Style)->setFontBold();
        $type = fn (string $t) => $t === FundTransaction::OUT ? __('fund.money_out') : __('fund.money_in');

        $path = tempnam(sys_get_temp_dir(), 'fund-entries');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr(__('fund.entries_title'), 0, 31));
        $writer->addRow(Row::fromValues([app(TenantContext::class)->get()?->name ?? '', __('fund.entries_title')], $bold));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([__('fund.no'), __('fund.date'), __('fund.for_month'), __('fund.fund_no'), __('fund.fund'), __('fund.type'), __('fund.party'), __('fund.remark'), __('fund.amount'), __('fund.currency'), 'USD', 'KHR'], $bold));
        foreach ($entries->values() as $i => $e) {
            $pair = Money::fields('a', $e->amount, $e->fund?->currency, $e->transacted_on);
            $writer->addRow(Row::fromValues([
                $i + 1,
                $e->transacted_on?->format('d-m-Y'),
                $e->for_month?->format('m-Y'),
                $e->fund?->fund_no,
                $e->fund?->name,
                $type($e->type),
                $e->party,
                $e->remark,
                (float) $e->amount,
                $e->fund?->currency,
                $pair['a_usd'],
                $pair['a_khr'],
            ]));
        }
        $writer->addRow(Row::fromValues([]));
        foreach (['in' => __('fund.total_in'), 'out' => __('fund.total_out')] as $key => $label) {
            if ($request->filled('type') && $request->input('type') !== $key) {
                continue;
            }
            $writer->addRow(Row::fromValues(['', $label, '', '', '', '', '', '', '', '', $totals["{$key}_usd"], $totals["{$key}_khr"]], $bold));
        }
        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="fund-money-in-out-'.now()->format('Y-m-d').'.xlsx"',
        ]);
    }

    /** @return Builder<FundTransaction> */
    private function filtered(Request $request): Builder
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in([FundTransaction::IN, FundTransaction::OUT])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'for_month' => ['nullable', 'date_format:Y-m'],
            'fund_id' => ['nullable', 'integer'],
            'fund_type' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        return FundTransaction::query()
            ->when($data['type'] ?? null, fn (Builder $q, string $type) => $q->where('fund_transactions.type', $type))
            ->when($data['date_from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('transacted_on', '>=', $d))
            ->when($data['date_to'] ?? null, fn (Builder $q, string $d) => $q->whereDate('transacted_on', '<=', $d))
            ->when($data['for_month'] ?? null, fn (Builder $q, string $m) => $q->whereDate('for_month', Carbon::createFromFormat('Y-m-d', $m.'-01')->toDateString()))
            ->when($data['fund_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('fund_id', (int) $id))
            ->when($data['fund_type'] ?? null, fn (Builder $q, string $type) => $q->whereHas('fund', fn (Builder $f) => $f->where('fund_type', $type)))
            ->when(trim((string) ($data['search'] ?? '')) !== '', function (Builder $q) use ($data) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $data['search'])).'%';
                $q->where(fn (Builder $w) => $w->where('party', 'ilike', $term)->orWhere('remark', 'ilike', $term)
                    ->orWhereHas('fund', fn (Builder $f) => $f->where('name', 'ilike', $term)->orWhere('fund_no', 'ilike', $term)));
            })
            ->orderByDesc('transacted_on')->orderByDesc('fund_transactions.id');
    }

    /**
     * Money in and out of every row the filters keep, in USD and KHR (each
     * day's amounts at that day's rate; null when a day has no rate).
     *
     * @param  Builder<FundTransaction>  $query
     * @return array<string, float|int|null>
     */
    private function totals(Builder $query): array
    {
        /** @var Collection<int, object{type: string, currency: string, day: string, amount: string, entries: int}> $groups */
        $groups = $query->reorder()->toBase()
            ->join('funds', 'funds.id', '=', 'fund_transactions.fund_id')
            ->selectRaw('fund_transactions.type, funds.currency, fund_transactions.transacted_on::date as day, sum(fund_transactions.amount) as amount, count(*) as entries')
            ->groupBy('fund_transactions.type', 'funds.currency', 'day')
            ->get();

        $out = ['count' => (int) $groups->sum('entries')];
        foreach ([FundTransaction::IN => 'in', FundTransaction::OUT => 'out'] as $type => $key) {
            $mine = $groups->where('type', $type);
            $usd = 0.0;
            $khr = 0.0;
            $known = true;
            foreach ($mine as $g) {
                $pair = Money::fields('a', $g->amount, $g->currency, $g->day);
                if ($pair['a_usd'] === null || $pair['a_khr'] === null) {
                    $known = false;
                    break;
                }
                $usd += $pair['a_usd'];
                $khr += $pair['a_khr'];
            }
            $out["{$key}_count"] = (int) $mine->sum('entries');
            $out["{$key}_usd"] = $known ? round($usd, 2) : null;
            $out["{$key}_khr"] = $known ? round($khr) : null;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function present(FundTransaction $e, Request $request): array
    {
        return [
            ...(new FundEntryResource($e))->toArray($request),
            'fund' => $e->fund === null ? null : ['id' => $e->fund->id, 'fund_no' => $e->fund->fund_no, 'name' => $e->fund->name, 'currency' => $e->fund->currency, 'status' => $e->fund->status, 'fund_type' => $e->fund->fund_type],
            ...Money::fields('amount', $e->amount, $e->fund?->currency, $e->transacted_on),
        ];
    }
}

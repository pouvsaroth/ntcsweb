<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Fund;
use App\Models\FundTransaction;
use App\Support\Billing\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fund > Reports: for a period, per currency (USD and KHR never added
 * together): balance at the start, money in, money out and balance at the
 * end, then the same by fund type and by fund, and money in by provider
 * (Fund > Providers). Optional fund type filter.
 *
 * Every figure also comes as `<field>_usd` and `<field>_khr`, so USD and
 * KHR funds can be read (and added up, in `all`) side by side: money in and
 * out at the exchange rate on each transaction's date; a balance at the rate
 * on the day it is measured (opening: the day before `from`; closing: `to`).
 */
final class FundReportController extends Controller
{
    private const MONEY = ['opening', 'money_in', 'money_out', 'closing'];

    /** `opening_usd`, `opening_khr`, … */
    private static function bothKeys(): array
    {
        return array_merge(...array_map(fn ($f) => ["{$f}_usd", "{$f}_khr"], self::MONEY));
    }

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Fund::class);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'fund_type' => ['nullable', 'string', 'max:50'],
        ]);
        // Normalised to yyyy-mm-dd (they go into the SQL sums below).
        $from = Carbon::parse($data['from'] ?? now()->startOfYear())->toDateString();
        $to = Carbon::parse($data['to'] ?? now())->toDateString();

        $out = "fund_transactions.type = '".FundTransaction::OUT."'";
        $signed = "CASE WHEN {$out} THEN -fund_transactions.amount ELSE fund_transactions.amount END";

        $rows = Fund::query()
            ->leftJoin('fund_transactions', 'fund_transactions.fund_id', '=', 'funds.id')
            ->when($data['fund_type'] ?? null, fn ($q, $type) => $q->where('funds.fund_type', $type))
            ->where('funds.opened_on', '<=', $to)
            ->groupBy('funds.id', 'funds.fund_no', 'funds.name', 'funds.fund_type', 'funds.currency', 'funds.status', 'funds.target_amount')
            ->orderBy('funds.currency')
            ->orderBy('funds.fund_no')
            ->get([
                'funds.id', 'funds.fund_no', 'funds.name', 'funds.fund_type', 'funds.currency', 'funds.status', 'funds.target_amount',
                DB::raw("COALESCE(SUM(CASE WHEN fund_transactions.transacted_on < '{$from}' THEN {$signed} ELSE 0 END), 0) as opening"),
                DB::raw("COALESCE(SUM(CASE WHEN NOT ({$out}) AND fund_transactions.transacted_on BETWEEN '{$from}' AND '{$to}' THEN fund_transactions.amount ELSE 0 END), 0) as money_in"),
                DB::raw("COALESCE(SUM(CASE WHEN {$out} AND fund_transactions.transacted_on BETWEEN '{$from}' AND '{$to}' THEN fund_transactions.amount ELSE 0 END), 0) as money_out"),
                DB::raw("COALESCE(SUM(CASE WHEN fund_transactions.transacted_on <= '{$to}' THEN {$signed} ELSE 0 END), 0) as closing"),
            ]);

        // Money in / out in USD and in KHR, from per-day sums so each
        // transaction is converted at the rate on its own date.
        $both = [];
        $daily = FundTransaction::query()
            ->join('funds', 'funds.id', '=', 'fund_transactions.fund_id')
            ->whereIn('fund_transactions.fund_id', $rows->pluck('id'))
            ->whereBetween('fund_transactions.transacted_on', [$from, $to])
            ->groupBy('fund_transactions.fund_id', 'funds.currency', 'fund_transactions.transacted_on', 'fund_transactions.type')
            ->get(['fund_transactions.fund_id', 'funds.currency', 'fund_transactions.transacted_on', 'fund_transactions.type', DB::raw('SUM(fund_transactions.amount) as total')]);
        foreach ($daily as $day) {
            $date = Carbon::parse($day->transacted_on)->toDateString();
            $pair = Money::pairOn($day->total, $day->currency, $date);
            $isOut = $day->type === FundTransaction::OUT;
            $bucket = &$both[$day->fund_id];
            $bucket ??= array_fill_keys(self::bothKeys(), 0.0);
            foreach (['usd' => $pair['USD'] ?? 0.0, 'khr' => $pair['KHR'] ?? 0.0] as $side => $amount) {
                $bucket[($isOut ? 'money_out' : 'money_in')."_{$side}"] += $amount;
            }
            unset($bucket);
        }
        $roundBoth = fn (array $b) => array_map(fn ($k) => str_ends_with($k, '_khr') ? round($b[$k]) : round($b[$k], 2), array_combine(array_keys($b), array_keys($b)));

        $funds = [];
        $types = [];
        $totals = [];
        $all = ['funds' => 0, ...array_fill_keys(self::bothKeys(), 0.0)];
        foreach ($rows as $row) {
            $money = array_map(fn ($field) => round((float) $row->{$field}, 2), array_combine(self::MONEY, self::MONEY));
            // Funds with nothing at any point in the period are left out.
            if (max(array_map('abs', $money)) < 0.001) {
                continue;
            }
            $c = $row->currency;
            $converted = $both[$row->id] ?? array_fill_keys(self::bothKeys(), 0.0);
            $opening = Money::pairOn($money['opening'], $c, Carbon::parse($from)->subDay()->toDateString());
            $closing = Money::pairOn($money['closing'], $c, $to);
            $converted = [
                ...$converted,
                'opening_usd' => $opening['USD'] ?? 0.0, 'opening_khr' => $opening['KHR'] ?? 0.0,
                'closing_usd' => $closing['USD'] ?? 0.0, 'closing_khr' => $closing['KHR'] ?? 0.0,
            ];
            $funds[] = [
                'id' => (int) $row->id,
                'fund_no' => $row->fund_no,
                'name' => $row->name,
                'fund_type' => $row->fund_type,
                'currency' => $c,
                'status' => $row->status,
                'target_amount' => $row->target_amount !== null ? (float) $row->target_amount : null,
                ...$money,
                ...$roundBoth($converted),
            ];

            $types["{$row->fund_type}|{$c}"] ??= ['fund_type' => $row->fund_type, 'currency' => $c, 'funds' => 0, ...array_fill_keys(self::MONEY, 0.0), ...array_fill_keys(self::bothKeys(), 0.0)];
            $totals[$c] ??= ['currency' => $c, 'funds' => 0, ...array_fill_keys(self::MONEY, 0.0), ...array_fill_keys(self::bothKeys(), 0.0)];
            foreach ([&$types["{$row->fund_type}|{$c}"], &$totals[$c]] as &$bucket) {
                $bucket['funds']++;
                foreach (self::MONEY as $field) {
                    $bucket[$field] += $money[$field];
                }
                foreach (self::bothKeys() as $key) {
                    $bucket[$key] += $converted[$key];
                }
            }
            unset($bucket);
            $all['funds']++;
            foreach (self::bothKeys() as $key) {
                $all[$key] += $converted[$key];
            }
        }

        $round = fn (array $row) => [
            ...$row,
            ...array_map(fn ($v) => round($v, 2), array_intersect_key($row, array_flip(self::MONEY))),
            ...$roundBoth(array_intersect_key($row, array_flip(self::bothKeys()))),
        ];
        $types = array_map($round, array_values($types));
        usort($types, fn ($a, $b) => [$a['currency'], -$a['closing']] <=> [$b['currency'], -$b['closing']]);

        // Money in during the period by who provided it (unnamed money in as one row).
        $providers = FundTransaction::query()
            ->join('funds', 'funds.id', '=', 'fund_transactions.fund_id')
            ->leftJoin('fund_providers', 'fund_providers.id', '=', 'fund_transactions.fund_provider_id')
            ->where('fund_transactions.type', FundTransaction::IN)
            ->whereBetween('fund_transactions.transacted_on', [$from, $to])
            ->when($data['fund_type'] ?? null, fn ($q, $type) => $q->where('funds.fund_type', $type))
            ->groupBy('fund_providers.id', 'fund_providers.code', 'fund_providers.name', 'funds.currency', 'fund_transactions.transacted_on')
            ->get([
                'fund_providers.id', 'fund_providers.code', 'fund_providers.name', 'funds.currency', 'fund_transactions.transacted_on',
                DB::raw('COUNT(*) as entries'), DB::raw('SUM(fund_transactions.amount) as money_in'),
            ])
            ->groupBy(fn ($r) => ($r->id ?? 'none').'|'.$r->currency)
            ->map(function ($days) {
                $first = $days->first();
                $pairs = $days->map(fn ($d) => Money::pairOn($d->money_in, $d->currency, Carbon::parse($d->transacted_on)->toDateString()));

                return [
                    'provider_id' => $first->id !== null ? (int) $first->id : null,
                    'code' => $first->code,
                    'name' => $first->name,
                    'currency' => $first->currency,
                    'entries' => (int) $days->sum('entries'),
                    'money_in' => round((float) $days->sum('money_in'), 2),
                    'money_in_usd' => round((float) $pairs->sum(fn ($p) => $p['USD'] ?? 0), 2),
                    'money_in_khr' => round((float) $pairs->sum(fn ($p) => $p['KHR'] ?? 0)),
                ];
            })
            ->sortBy(fn ($r) => [$r['currency'], $r['provider_id'] === null ? 1 : 0, -$r['money_in']])
            ->values()
            ->all();

        return ApiResponse::success([
            'from' => $from,
            'to' => $to,
            'totals' => array_map(fn ($c) => $round($totals[$c] ?? ['currency' => $c, 'funds' => 0, ...array_fill_keys(self::MONEY, 0.0), ...array_fill_keys(self::bothKeys(), 0.0)]), ['USD', 'KHR']),
            // Every fund together, in USD and in KHR.
            'all' => ['funds' => $all['funds'], ...$roundBoth(array_intersect_key($all, array_flip(self::bothKeys())))],
            'types' => $types,
            'funds' => $funds,
            'providers' => $providers,
        ]);
    }
}

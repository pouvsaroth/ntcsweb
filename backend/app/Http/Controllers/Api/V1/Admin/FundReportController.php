<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Fund;
use App\Models\FundTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fund > Reports: for a period, per currency (USD and KHR never added
 * together): balance at the start, money in, money out and balance at the
 * end, then the same by fund type and by fund. Optional fund type filter.
 */
final class FundReportController extends Controller
{
    private const MONEY = ['opening', 'money_in', 'money_out', 'closing'];

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

        $funds = [];
        $types = [];
        $totals = [];
        foreach ($rows as $row) {
            $money = array_map(fn ($field) => round((float) $row->{$field}, 2), array_combine(self::MONEY, self::MONEY));
            // Funds with nothing at any point in the period are left out.
            if (max(array_map('abs', $money)) < 0.001) {
                continue;
            }
            $c = $row->currency;
            $funds[] = [
                'id' => (int) $row->id,
                'fund_no' => $row->fund_no,
                'name' => $row->name,
                'fund_type' => $row->fund_type,
                'currency' => $c,
                'status' => $row->status,
                'target_amount' => $row->target_amount !== null ? (float) $row->target_amount : null,
                ...$money,
            ];

            $types["{$row->fund_type}|{$c}"] ??= ['fund_type' => $row->fund_type, 'currency' => $c, 'funds' => 0, ...array_fill_keys(self::MONEY, 0.0)];
            $totals[$c] ??= ['currency' => $c, 'funds' => 0, ...array_fill_keys(self::MONEY, 0.0)];
            foreach ([&$types["{$row->fund_type}|{$c}"], &$totals[$c]] as &$bucket) {
                $bucket['funds']++;
                foreach (self::MONEY as $field) {
                    $bucket[$field] += $money[$field];
                }
            }
            unset($bucket);
        }

        $round = fn (array $row) => [...$row, ...array_map(fn ($v) => round($v, 2), array_intersect_key($row, array_flip(self::MONEY)))];
        $types = array_map($round, array_values($types));
        usort($types, fn ($a, $b) => [$a['currency'], -$a['closing']] <=> [$b['currency'], -$b['closing']]);

        return ApiResponse::success([
            'from' => $from,
            'to' => $to,
            'totals' => array_map(fn ($c) => $round($totals[$c] ?? ['currency' => $c, 'funds' => 0, ...array_fill_keys(self::MONEY, 0.0)]), ['USD', 'KHR']),
            'types' => $types,
            'funds' => $funds,
        ]);
    }
}

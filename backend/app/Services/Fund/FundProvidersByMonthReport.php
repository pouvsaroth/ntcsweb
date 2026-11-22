<?php

declare(strict_types=1);

namespace App\Services\Fund;

use App\Models\FundTransaction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Fund > Providers by month: money in per provider (Fund > Providers) or
 * staff member (HRM > Staff), one column per month, with a Total column and
 * a Total row per currency (USD and KHR are never added together).
 *
 * Each money in counts under its "For month"; one saved without it counts
 * under the month of its date.
 */
final class FundProvidersByMonthReport
{
    /** At most this many month columns. */
    public const MAX_MONTHS = 24;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{from_month?: string|null, to_month?: string|null, who?: string|null, fund_type?: string|null}  $filters
     *                                                                                                                       who: '' (everyone), 'providers', 'staff', 'provider:<id>', 'staff:<id>'
     * @return array{from_month: string, to_month: string, months: list<string>, rows: list<array<string, mixed>>, totals: list<array<string, mixed>>}
     */
    public function build(array $filters): array
    {
        $now = Carbon::now($this->context->get()?->timezone ?: 'Asia/Phnom_Penh');
        $from = Carbon::createFromFormat('Y-m-d', ($filters['from_month'] ?? null ?: $now->format('Y').'-01').'-01')->startOfMonth();
        $to = Carbon::createFromFormat('Y-m-d', ($filters['to_month'] ?? null ?: $now->format('Y-m')).'-01')->startOfMonth();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInMonths($to) >= self::MAX_MONTHS) {
            $from = $to->copy()->subMonths(self::MAX_MONTHS - 1);
        }

        $months = [];
        for ($m = $from->copy(); $m->lte($to); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        $who = (string) ($filters['who'] ?? '');
        [$kind, $id] = array_pad(explode(':', $who, 2), 2, null);
        $month = 'COALESCE(fund_transactions.for_month, fund_transactions.transacted_on)';

        $lines = FundTransaction::query()
            ->join('funds', 'funds.id', '=', 'fund_transactions.fund_id')
            ->leftJoin('fund_providers', 'fund_providers.id', '=', 'fund_transactions.fund_provider_id')
            ->leftJoin('staff', 'staff.id', '=', 'fund_transactions.staff_id')
            ->where('fund_transactions.type', FundTransaction::IN)
            ->whereRaw("{$month} >= ?", [$from->toDateString()])
            ->whereRaw("{$month} <= ?", [$to->copy()->endOfMonth()->toDateString()])
            ->when($filters['fund_type'] ?? null, fn ($q, $type) => $q->where('funds.fund_type', $type))
            ->when($kind === 'providers', fn ($q) => $q->whereNotNull('fund_transactions.fund_provider_id'))
            ->when($kind === 'staff' && $id === null, fn ($q) => $q->whereNotNull('fund_transactions.staff_id')->whereNull('fund_transactions.fund_provider_id'))
            ->when($kind === 'provider' && $id !== null, fn ($q) => $q->where('fund_transactions.fund_provider_id', (int) $id))
            ->when($kind === 'staff' && $id !== null, fn ($q) => $q->where('fund_transactions.staff_id', (int) $id)->whereNull('fund_transactions.fund_provider_id'))
            ->get([
                'fund_providers.id as provider_id', 'fund_providers.code as provider_code', 'fund_providers.name as provider_name',
                'staff.id as staff_id', 'staff.employee_code', 'staff.first_name', 'staff.last_name',
                'funds.currency', 'fund_transactions.amount',
                DB::raw("{$month} as month_date"),
            ]);

        $rows = [];
        $totals = [];
        foreach ($lines as $line) {
            $isProvider = $line->provider_id !== null;
            $isStaff = ! $isProvider && $line->staff_id !== null;
            $currency = (string) $line->currency;
            $key = ($isProvider ? 'p'.$line->provider_id : ($isStaff ? 's'.$line->staff_id : 'none')).'|'.$currency;
            $m = substr((string) $line->month_date, 0, 7);
            $amount = (float) $line->amount;

            $rows[$key] ??= [
                'key' => $key,
                'provider_id' => $isProvider ? (int) $line->provider_id : null,
                'staff_id' => $isStaff ? (int) $line->staff_id : null,
                'code' => $isProvider ? $line->provider_code : ($isStaff ? $line->employee_code : null),
                'name' => $isProvider ? $line->provider_name : ($isStaff ? trim("{$line->first_name} {$line->last_name}") : null),
                'currency' => $currency,
                'months' => array_fill_keys($months, 0.0),
                'total' => 0.0,
                'entries' => 0,
            ];
            $rows[$key]['months'][$m] = ($rows[$key]['months'][$m] ?? 0) + $amount;
            $rows[$key]['total'] += $amount;
            $rows[$key]['entries']++;

            $totals[$currency] ??= ['currency' => $currency, 'months' => array_fill_keys($months, 0.0), 'total' => 0.0, 'people' => 0];
            $totals[$currency]['months'][$m] = ($totals[$currency]['months'][$m] ?? 0) + $amount;
            $totals[$currency]['total'] += $amount;
        }

        $round = fn (array $r) => [...$r, 'months' => array_map(fn ($v) => round($v, 2), $r['months']), 'total' => round($r['total'], 2)];
        $rows = array_values(array_map($round, $rows));
        // USD then KHR, then biggest giver first; unnamed money in last.
        usort($rows, fn ($a, $b) => [$a['currency'] !== 'USD', $a['currency'], $a['name'] === null ? 1 : 0, -$a['total'], (string) $a['name']]
            <=> [$b['currency'] !== 'USD', $b['currency'], $b['name'] === null ? 1 : 0, -$b['total'], (string) $b['name']]);
        foreach ($rows as $row) {
            if ($row['name'] !== null) {
                $totals[$row['currency']]['people']++;
            }
        }
        // USD first, then KHR.
        uksort($totals, fn ($a, $b) => [$a !== 'USD', $a] <=> [$b !== 'USD', $b]);
        $totals = array_values(array_map($round, $totals));

        return [
            'from_month' => $from->format('Y-m'),
            'to_month' => $to->format('Y-m'),
            'months' => $months,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    public static function money(float $amount, string $currency): string
    {
        return $currency === 'KHR' ? number_format($amount, 0).' ៛' : '$'.number_format($amount, 2);
    }

    /** One sheet: per currency, the provider rows then their Total row. */
    public function excel(array $report): string
    {
        $tenant = $this->context->getOrFail();
        $months = $report['months'];
        $label = fn (string $m) => Carbon::createFromFormat('Y-m-d', $m.'-01')->format('m-Y');

        $options = new Options;
        $options->setColumnWidth(6, 1);
        $options->setColumnWidth(14, 2);
        $options->setColumnWidth(28, 3);
        $options->setColumnWidth(8, 4);
        foreach ($months as $i => $m) {
            $options->setColumnWidth(13, $i + 5);
        }
        $options->setColumnWidth(15, count($months) + 5);

        $path = tempnam(sys_get_temp_dir(), 'fundmonth');
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('By month');
        $bold = (new Style)->setFontBold();
        $head = (new Style)->setFontBold()->setBackgroundColor('F3F4F6');

        $writer->addRow(Row::fromValues([$tenant->name], (new Style)->setFontBold()->setFontSize(14)));
        $writer->addRow(Row::fromValues([__('fund_report.by_month_title').' ('.$label($report['from_month']).' – '.$label($report['to_month']).')'], $bold));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([
            __('fund_report.no'), __('fund_report.code'), __('fund_report.provided_by'), __('fund_report.currency'),
            ...array_map($label, $months), __('fund_report.total'),
        ], $head));

        foreach ($report['totals'] as $total) {
            $n = 0;
            foreach ($report['rows'] as $row) {
                if ($row['currency'] !== $total['currency']) {
                    continue;
                }
                $writer->addRow(Row::fromValues([
                    ++$n, $row['code'] ?? '', $row['name'] ?? __('fund_report.no_name'), $row['currency'],
                    ...array_map(fn ($m) => $row['months'][$m] ?: null, $months), $row['total'],
                ]));
            }
            $writer->addRow(Row::fromValues([
                '', '', __('fund_report.total'), $total['currency'],
                ...array_map(fn ($m) => $total['months'][$m], $months), $total['total'],
            ], $head));
            $writer->addRow(Row::fromValues([]));
        }

        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}

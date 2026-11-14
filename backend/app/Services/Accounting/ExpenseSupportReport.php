<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Expense;
use App\Models\ExpenseEntity;
use App\Models\ExpenseSupport;
use App\Models\Tenant;
use App\Services\Billing\CurrencyConversionService;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Billing\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Expenses > Support: for each Expense for and currency, what was spent,
 * the money people gave, and what that leaves (expenses − support money) —
 * plus the support list itself, for Excel / PDF.
 *
 * Expenses count once they are submitted: pending, approved or paid (not
 * draft, rejected or cancelled). Food counts as people and items, never as
 * money.
 */
class ExpenseSupportReport
{
    public const COUNTED = [ExpenseStatus::PENDING_APPROVAL, ExpenseStatus::APPROVED, ExpenseStatus::PAID];

    public function __construct(
        private readonly TenantContext $context,
        private readonly BrowsershotRenderer $renderer,
        private readonly CurrencyConversionService $conversion,
    ) {}

    /**
     * @param  array{expense_entity_id?: int|null, from?: string|null, to?: string|null, kind?: string|null, search?: string|null}  $filters
     * @return array{filters: array<string, mixed>, summary: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function build(array $filters): array
    {
        $entityId = $filters['expense_entity_id'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $supports = fn (): Builder => ExpenseSupport::query()
            ->when($entityId, fn ($q) => $q->where('expense_entity_id', $entityId))
            ->when($from, fn ($q) => $q->whereDate('support_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('support_date', '<=', $to));

        // Which Expense for: the one picked, else every one that has support.
        $entityIds = $entityId ? [(int) $entityId] : ExpenseSupport::query()->distinct()->pluck('expense_entity_id')->all();
        $entities = ExpenseEntity::query()->whereIn('id', $entityIds)->orderBy('name')->get(['id', 'name']);

        $spent = Expense::query()
            ->whereIn('expense_entity_id', $entityIds)->whereIn('status', self::COUNTED)
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->selectRaw('expense_entity_id, currency, sum(amount) as total, count(*) as n')
            ->groupBy('expense_entity_id', 'currency')->get();

        $given = $supports()->where('kind', ExpenseSupport::MONEY)
            ->selectRaw('expense_entity_id, currency, sum(amount) as total, count(*) as n')
            ->groupBy('expense_entity_id', 'currency')->get();

        $people = $supports()->selectRaw('expense_entity_id, kind, count(*) as n')->groupBy('expense_entity_id', 'kind')->get();

        // The same sums by date too, so each can be shown in USD and in KHR
        // with every expense / support converted at the rate on its own date.
        $spentByDate = Expense::query()
            ->whereIn('expense_entity_id', $entityIds)->whereIn('status', self::COUNTED)
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->selectRaw('expense_entity_id, currency, DATE(expense_date) as tx_date, sum(amount) as total')
            ->groupBy('expense_entity_id', 'currency', 'tx_date')->get();
        $givenByDate = $supports()->where('kind', ExpenseSupport::MONEY)
            ->selectRaw('expense_entity_id, currency, DATE(support_date) as tx_date, sum(amount) as total')
            ->groupBy('expense_entity_id', 'currency', 'tx_date')->get();
        $tenant = $this->context->getOrFail();
        $inBoth = fn ($rows) => [
            'usd' => CurrencyConversionService::in(Tenant::CURRENCY_USD, fn () => $this->conversion->sumConverted($rows, $tenant)),
            'khr' => round(CurrencyConversionService::in(Tenant::CURRENCY_KHR, fn () => $this->conversion->sumConverted($rows, $tenant))),
        ];

        $summary = [];
        foreach ($entities as $entity) {
            $currencies = collect([...$spent->where('expense_entity_id', $entity->id)->pluck('currency'), ...$given->where('expense_entity_id', $entity->id)->pluck('currency')])
                ->filter()->unique()->sort()->values();
            $lines = $currencies->map(function (string $currency) use ($spent, $given, $entity) {
                $expenses = round((float) ($spent->where('expense_entity_id', $entity->id)->firstWhere('currency', $currency)?->total ?? 0), 2);
                $support = round((float) ($given->where('expense_entity_id', $entity->id)->firstWhere('currency', $currency)?->total ?? 0), 2);

                return ['currency' => $currency, 'expenses' => $expenses, 'support' => $support, 'net' => round($expenses - $support, 2)];
            })->all();

            $expenses = $inBoth($spentByDate->where('expense_entity_id', $entity->id));
            $support = $inBoth($givenByDate->where('expense_entity_id', $entity->id));

            $summary[] = [
                'expense_entity' => ['id' => $entity->id, 'name' => $entity->name],
                'currencies' => $lines,
                'totals' => [
                    'expenses_usd' => $expenses['usd'], 'expenses_khr' => $expenses['khr'],
                    'support_usd' => $support['usd'], 'support_khr' => $support['khr'],
                    'net_usd' => round($expenses['usd'] - $support['usd'], 2), 'net_khr' => round($expenses['khr'] - $support['khr']),
                ],
                'money_count' => (int) ($people->where('expense_entity_id', $entity->id)->firstWhere('kind', ExpenseSupport::MONEY)?->n ?? 0),
                'food_count' => (int) ($people->where('expense_entity_id', $entity->id)->firstWhere('kind', ExpenseSupport::FOOD)?->n ?? 0),
            ];
        }

        $rows = $supports()->with('expenseEntity:id,name')
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")
                ->orWhere('items', 'ilike', "%{$s}%")->orWhere('note', 'ilike', "%{$s}%")))
            ->orderBy('support_date')->orderBy('id')->get()
            ->map(fn (ExpenseSupport $s) => [
                'support_date' => $s->support_date?->format('d-m-Y'),
                'name' => $s->name,
                'phone' => $s->phone,
                'relationship' => $s->relationship ? __('expense_support.relationships.'.$s->relationship) : '',
                'kind' => __('expense_support.kinds.'.$s->kind),
                'items' => $s->items,
                'amount' => $s->amount !== null ? (float) $s->amount : null,
                'currency' => $s->currency,
                // The money in USD and in KHR at the rate on its support date.
                ...($s->amount !== null
                    ? Money::pairText($s->amount, $s->currency, $this->rateOn($s->support_date?->toDateString()))
                    : ['USD' => '', 'KHR' => '']),
                'expense_for' => $s->expenseEntity?->name,
                'note' => $s->note,
            ])->all();

        return ['filters' => array_filter(['from' => $from, 'to' => $to]), 'summary' => $summary, 'rows' => $rows];
    }

    /** @var array<string, float|null> */
    private array $rates = [];

    private function rateOn(?string $date): ?float
    {
        $key = $date ?? 'today';

        return $this->rates[$key] ??= (fn ($row) => $row !== null ? (float) $row->khr_per_usd : null)(Money::rateOn($date));
    }

    public static function money(float $amount, ?string $currency): string
    {
        return $currency === 'KHR' ? number_format($amount, 0).' ៛' : '$'.number_format($amount, 2);
    }

    public function excel(array $report): string
    {
        $tenant = $this->context->getOrFail();
        $options = new Options;
        foreach ([24, 14, 26, 14, 14, 10, 30, 14, 16, 20, 30] as $i => $width) {
            $options->setColumnWidth($width, $i + 1);
        }

        $path = tempnam(sys_get_temp_dir(), 'support');
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Support');
        $bold = (new Style)->setFontBold();
        $head = (new Style)->setFontBold()->setBackgroundColor('F3F4F6');

        $writer->addRow(Row::fromValues([$tenant->name], (new Style)->setFontBold()->setFontSize(14)));
        $writer->addRow(Row::fromValues([__('expense_support.title').$this->period($report)], $bold));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues([
            __('expense_support.expense_for'),
            __('expense_support.expenses').' (USD)', __('expense_support.expenses').' (KHR)',
            __('expense_support.support_money').' (USD)', __('expense_support.support_money').' (KHR)',
            __('expense_support.net').' (USD)', __('expense_support.net').' (KHR)',
        ], $head));
        foreach ($report['summary'] as $block) {
            $t = $block['totals'];
            $writer->addRow(Row::fromValues([$block['expense_entity']['name'], $t['expenses_usd'], $t['expenses_khr'], $t['support_usd'], $t['support_khr'], $t['net_usd'], $t['net_khr']]));
        }
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues([
            __('expense_support.no'), __('expense_support.date'), __('expense_support.name'), __('expense_support.phone'), __('expense_support.relationship'),
            __('expense_support.kind'), __('expense_support.items'), __('expense_support.amount').' (USD)', __('expense_support.amount').' (KHR)',
            __('expense_support.expense_for'), __('expense_support.note'),
        ], $head));
        $wrap = (new Style)->setShouldWrapText();
        foreach ($report['rows'] as $i => $row) {
            $writer->addRow(Row::fromValues([
                $i + 1, $row['support_date'], $row['name'], $row['phone'] ?? '', $row['relationship'], $row['kind'], $row['items'] ?? '',
                $row['USD'], $row['KHR'], $row['expense_for'] ?? '', $row['note'] ?? '',
            ], $wrap));
        }

        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    public function pdf(array $report): string
    {
        $tenant = $this->context->getOrFail();

        $html = view('pdf.expense-support', [
            'tenant' => $tenant,
            'logoDataUri' => PdfImageEncoder::dataUri($tenant->logoPath()),
            'khmerFontRegular' => KhmerFont::dataUri('Regular'),
            'khmerFontBold' => KhmerFont::dataUri('Bold'),
            'generatedAt' => now($tenant->timezone ?: 'Asia/Phnom_Penh')->format('d-m-Y H:i'),
            'report' => $report,
            'period' => $this->period($report),
        ])->render();

        return $this->renderer->render($html, 'A4', 10, landscape: true);
    }

    private function period(array $report): string
    {
        $fmt = fn (?string $d) => $d ? date('d-m-Y', (int) strtotime($d)) : '…';
        $f = $report['filters'];

        return isset($f['from']) || isset($f['to']) ? ' ('.$fmt($f['from'] ?? null).' – '.$fmt($f['to'] ?? null).')' : '';
    }
}

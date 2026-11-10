<?php

declare(strict_types=1);

namespace App\Services\Borrowings;

use App\Models\CustomerLoan;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Loan > Owe: a loan's payment list (No, Pay date, Start amount, End amount,
 * Principal payment, Interest, Payment, with totals) as Excel or PDF. The
 * image is drawn in the browser from the same rows.
 */
final class CustomerLoanPlanExport
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BrowsershotRenderer $renderer,
    ) {}

    /**
     * @return array{heading: string, terms: string, rows: list<array<string, mixed>>, totals: array{principal: float, interest: float, total: float}}
     */
    public function build(CustomerLoan $loan): array
    {
        $loan->loadMissing('customer');
        $rows = BorrowingSchedule::for($loan);

        $type = __('customer_loan_plan.types.'.$loan->interest_type);
        $interest = $loan->interest_type === 'none'
            ? $type
            : rtrim(rtrim(number_format((float) $loan->interest_rate, 4), '0'), '.').'% '.__('customer_loan_plan.per_'.$loan->interest_period).' ('.$type.')';

        return [
            'heading' => trim(($loan->customer?->name ?? '').' · '.$loan->loan_no, ' ·'),
            'terms' => implode('  ·  ', array_filter([
                __('customer_loan_plan.amount').': '.$this->money((float) $loan->amount, (string) $loan->currency),
                __('customer_loan_plan.lent_on').': '.$loan->lent_on?->format('d-m-Y'),
                $interest,
                $loan->term_months ? __('customer_loan_plan.months', ['count' => $loan->term_months]) : null,
            ])),
            'rows' => array_map(fn (array $r) => [
                ...$r,
                'start' => $r['start'] ?? $r['balance'] + $r['principal'],
                'date' => Carbon::parse($r['due_on'])->format('d-m-Y'),
            ], $rows),
            'totals' => BorrowingSchedule::totals($rows),
        ];
    }

    public function excel(CustomerLoan $loan): string
    {
        $plan = $this->build($loan);
        $tenant = $this->context->getOrFail();
        $format = $loan->currency === 'KHR' ? '#,##0' : '#,##0.00';
        $number = (new Style)->setFormat($format);
        $bold = (new Style)->setFontBold();
        $boldNumber = (new Style)->setFontBold()->setFormat($format);

        $options = new Options;
        $options->setColumnWidth(6, 1);
        $options->setColumnWidth(13, 2);
        $options->setColumnWidthForRange(16, 3, 7);

        $path = tempnam(sys_get_temp_dir(), 'loanplan');
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Payment list');

        $writer->addRow(Row::fromValues([$tenant->name], (new Style)->setFontBold()->setFontSize(14)));
        $writer->addRow(Row::fromValues([__('customer_loan_plan.title').' · '.$plan['heading']], $bold));
        $writer->addRow(Row::fromValues([$plan['terms']]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues($this->headers(), (new Style)->setFontBold()->setBackgroundColor('F3F4F6')->setCellAlignment(CellAlignment::CENTER)->setShouldWrapText()));

        foreach ($plan['rows'] as $r) {
            $writer->addRow(Row::fromValuesWithStyles(
                [$r['no'], $r['date'], $r['start'], $r['balance'], $r['principal'], $r['interest'], $r['total']],
                null,
                [2 => $number, 3 => $number, 4 => $number, 5 => $number, 6 => $number],
            ));
        }
        $writer->addRow(Row::fromValuesWithStyles(
            [__('customer_loan_plan.total'), '', '', '', $plan['totals']['principal'], $plan['totals']['interest'], $plan['totals']['total']],
            $bold,
            [4 => $boldNumber, 5 => $boldNumber, 6 => $boldNumber],
        ));

        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    public function pdf(CustomerLoan $loan): string
    {
        $tenant = $this->context->getOrFail();
        $currency = (string) $loan->currency;

        $html = view('pdf.customer-loan-plan', [
            'tenant' => $tenant,
            'logoDataUri' => PdfImageEncoder::dataUri($tenant->logoPath()),
            'khmerFontRegular' => KhmerFont::dataUri('Regular'),
            'khmerFontBold' => KhmerFont::dataUri('Bold'),
            'generatedAt' => now($tenant->timezone ?: 'Asia/Phnom_Penh')->format('d-m-Y H:i'),
            'plan' => $this->build($loan),
            'headers' => $this->headers(),
            'money' => fn (float $v) => $this->money($v, $currency),
        ])->render();

        return $this->renderer->render($html, 'A4', 10);
    }

    public static function fileName(CustomerLoan $loan, string $extension): string
    {
        return 'payment-list-'.($loan->loan_no ?: 'loan').'.'.$extension;
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            __('customer_loan_plan.no'), __('customer_loan_plan.pay_date'), __('customer_loan_plan.start_amount'), __('customer_loan_plan.end_amount'),
            __('customer_loan_plan.principal_payment'), __('customer_loan_plan.interest'), __('customer_loan_plan.payment'),
        ];
    }

    private function money(float $value, string $currency): string
    {
        return $currency === 'KHR' ? number_format($value, 0).' ៛' : '$'.number_format($value, 2);
    }
}

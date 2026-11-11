<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Platform\Models\BillingSettings;
use App\Platform\Models\Quotation;
use App\Platform\Models\QuotationLine;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\KhmerFont;
use App\Services\Pdf\PdfImageEncoder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * A quotation as Excel, PDF or PNG image, in English or Khmer. The PDF and
 * the image are the same A4 page (Browsershot, so Khmer shapes correctly);
 * the header comes from Billing settings like the platform receipt.
 */
final class QuotationExport
{
    public const FORMATS = ['xlsx', 'pdf', 'png'];

    public const LANGS = ['en', 'km'];

    public function __construct(private readonly BrowsershotRenderer $renderer) {}

    /** @return array{body: string, type: string, filename: string} */
    public function export(Quotation $quotation, string $format, string $lang): array
    {
        $quotation->loadMissing(['lines', 'staff:id,name']);

        return match ($format) {
            'xlsx' => ['body' => $this->excel($quotation, $lang), 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'filename' => $quotation->number.'.xlsx'],
            'pdf' => ['body' => $this->renderer->render($this->html($quotation, $lang), 'A4', 12), 'type' => 'application/pdf', 'filename' => $quotation->number.'.pdf'],
            'png' => ['body' => $this->renderer->renderImage($this->html($quotation, $lang), 210, 297, 12), 'type' => 'image/png', 'filename' => $quotation->number.'.png'],
        };
    }

    public function html(Quotation $quotation, string $lang): string
    {
        $settings = BillingSettings::current();

        return view('pdf.platform-quotation', [
            'q' => $quotation,
            'lines' => $this->lines($quotation, $lang),
            'settings' => $settings,
            'lang' => $lang,
            'discountLabel' => $this->discountLabel($quotation, $lang),
            'l' => fn (string $key) => __('quotation.'.$key, [], $lang),
            'money' => fn ($v) => $this->money((float) $v, $quotation->currency),
            'qty' => fn ($v) => $this->qty((float) $v),
            'logoDataUri' => PdfImageEncoder::dataUri($settings->logoPath()),
            'khmerFontRegular' => KhmerFont::dataUri('Regular'),
            'khmerFontBold' => KhmerFont::dataUri('Bold'),
        ])->render();
    }

    public function excel(Quotation $quotation, string $lang): string
    {
        $settings = BillingSettings::current();
        $l = fn (string $key) => __('quotation.'.$key, [], $lang);
        $format = $quotation->currency === 'KHR' ? '#,##0" ៛"' : '"$"#,##0.00';
        $number = (new Style)->setFormat($format);
        $bold = (new Style)->setFontBold();
        $boldNumber = (new Style)->setFontBold()->setFormat($format);
        $wrap = (new Style)->setShouldWrapText();

        $options = new Options;
        $options->setColumnWidth(6, 1);
        $options->setColumnWidth(28, 2);
        $options->setColumnWidth(32, 3);
        $options->setColumnWidth(36, 4);
        $options->setColumnWidth(8, 5);
        $options->setColumnWidth(14, 6);
        $options->setColumnWidth(16, 7);

        $path = tempnam(sys_get_temp_dir(), 'quote');
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr($l('sheet'), 0, 31));

        $writer->addRow(Row::fromValues([$settings->company_name ?: config('app.name')], (new Style)->setFontBold()->setFontSize(14)));
        foreach (array_filter([$settings->company_address, collect([$settings->company_phone, $settings->company_email])->filter()->implode(' · ')]) as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([$l('title')], (new Style)->setFontBold()->setFontSize(13)));
        $writer->addRow(Row::fromValues([$l('number'), $quotation->number]));
        $writer->addRow(Row::fromValues([$l('date'), $quotation->issue_date?->format('d-m-Y')]));
        if ($quotation->valid_until) {
            $writer->addRow(Row::fromValues([$l('valid_until'), $quotation->valid_until->format('d-m-Y')]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([$l('to'), $quotation->customer_name], $bold));
        foreach (['contact' => $quotation->contact_name, 'phone' => $quotation->phone, 'email' => $quotation->email, 'address' => $quotation->address] as $key => $value) {
            if ($value) {
                $writer->addRow(Row::fromValues([$l($key), $value]));
            }
        }
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(
            [$l('no'), $l('module'), $l('item'), $l('description'), $l('qty'), $l('unit_price'), $l('amount')],
            (new Style)->setFontBold()->setBackgroundColor('F3F4F6')->setCellAlignment(CellAlignment::CENTER)->setShouldWrapText(),
        ));
        foreach ($this->lines($quotation, $lang) as $line) {
            $writer->addRow(Row::fromValuesWithStyles(
                [$line['no'], $line['module'], $line['name'], $line['description'], (float) $line['quantity'], (float) $line['unit_price'], (float) $line['amount']],
                null,
                [1 => $wrap, 2 => $wrap, 3 => $wrap, 5 => $number, 6 => $number],
            ));
        }

        $totals = [[$l('subtotal'), (float) $quotation->subtotal]];
        if ((float) $quotation->discount_amount > 0) {
            $totals[] = [$this->discountLabel($quotation, $lang), -(float) $quotation->discount_amount];
        }
        if ((float) $quotation->tax_amount > 0) {
            $totals[] = [$l('tax').' ('.$this->qty((float) $quotation->tax_percent).'%)', (float) $quotation->tax_amount];
        }
        foreach ($totals as [$label, $value]) {
            $writer->addRow(Row::fromValuesWithStyles(['', '', '', '', '', $label, $value], null, [6 => $number]));
        }
        $writer->addRow(Row::fromValuesWithStyles(['', '', '', '', '', $l('total'), (float) $quotation->total], $bold, [6 => $boldNumber]));

        if ($quotation->notes) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues([$l('notes')], $bold));
            $writer->addRow(Row::fromValues([$quotation->notes], $wrap));
        }

        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    public function discountLabel(Quotation $quotation, string $lang): string
    {
        $label = __('quotation.discount', [], $lang);

        return $quotation->discount_type === 'percent' ? $label.' ('.$this->qty((float) $quotation->discount_value).'%)' : $label;
    }

    /** @return list<array{no: int, level: ?string, module: string, name: string, description: string, quantity: string, unit_price: string, amount: string}> */
    private function lines(Quotation $quotation, string $lang): array
    {
        $km = $lang === 'km';

        return $quotation->lines->values()->map(fn (QuotationLine $line, int $i) => [
            'no' => $i + 1,
            'level' => $line->level,
            'module' => (string) (($km && $line->parent_label_km) ? $line->parent_label_km : $line->parent_label),
            'name' => (string) (($km && $line->name_km) ? $line->name_km : $line->name),
            'description' => (string) $line->description,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'amount' => (string) $line->amount,
        ])->all();
    }

    private function money(float $value, string $currency): string
    {
        $sign = $value < 0 ? '−' : '';

        return $currency === 'KHR' ? $sign.number_format(abs($value), 0).' ៛' : $sign.'$'.number_format(abs($value), 2);
    }

    private function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}

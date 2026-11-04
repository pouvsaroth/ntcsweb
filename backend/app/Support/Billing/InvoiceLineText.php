<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\InvoiceItem;
use App\Models\SchoolClass;

/**
 * The text printed for one invoice line, in the current locale.
 *
 * Lines the system writes itself ("Enrollment: …", "Exam fee: …", a company
 * class line) are stored in English. Printing in Khmer rebuilds the fixed
 * words in Khmer and keeps the names (package, class, student) as stored.
 * Any other line — typed by staff, or edited — prints exactly as stored.
 */
final class InvoiceLineText
{
    public static function for(InvoiceItem $item): string
    {
        $text = (string) $item->description;

        if ($item->reference_type === Enrollment::class && str_starts_with($text, 'Enrollment: ')) {
            return __('invoice.lines.enrollment', ['detail' => substr($text, strlen('Enrollment: '))]);
        }

        if ($item->reference_type === ExamApplication::class && str_starts_with($text, 'Exam fee: ')) {
            return __('invoice.lines.exam_fee', ['name' => substr($text, strlen('Exam fee: '))]);
        }

        // Written by CompanyInvoiceService: "{package} — {class} ({n} students), {from} to {to}".
        if ($item->reference_type === SchoolClass::class
            && preg_match('/^(.*) \((\d+) students\), (\d{2}-\d{2}-\d{4}) to (\d{2}-\d{2}-\d{4})$/su', $text, $m) === 1) {
            return __('invoice.lines.company_class', ['detail' => $m[1], 'count' => $m[2], 'from' => $m[3], 'to' => $m[4]]);
        }

        return $text;
    }
}

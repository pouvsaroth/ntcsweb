<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Billing\InvoicePdfService;
use App\Services\Billing\ReceiptPdfService;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use ReflectionMethod;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * An invoice or receipt printed in Khmer is all Khmer; printed in English it
 * is all English — including the lines the system writes itself.
 */
class InvoiceLanguageTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    private const PERMS = [Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW, Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE, Permissions::PAYMENTS_VIEW];

    private function invoiceHtml(Invoice $invoice, string $locale): string
    {
        return (new ReflectionMethod(InvoicePdfService::class, 'html'))->invoke(app(InvoicePdfService::class), $invoice->fresh(), $locale);
    }

    private function receiptHtml(Payment $payment, string $locale): string
    {
        return (new ReflectionMethod(ReceiptPdfService::class, 'html'))->invoke(app(ReceiptPdfService::class), $payment->fresh(), $locale);
    }

    public function test_a_company_class_invoice_and_its_receipt_print_in_one_language(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->setUpAcademicCatalog();
        $class = SchoolClass::factory()->forProgram($this->computerProgram)->create(['name' => 'Class A', 'company_id' => Company::factory()->create(['name' => 'ABC Ltd'])->id]);
        $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'students' => Student::factory()->count(2)->create()->map(fn ($s) => ['student_id' => $s->id])->all(),
        ])->assertCreated();
        $this->postJson('/api/v1/invoices/company-class', [
            'class_id' => $class->id, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30',
            'currency' => 'USD', 'amount' => 210, 'received_amount' => 100, 'payment_method' => 'CASH',
        ])->assertCreated();
        $invoice = Invoice::query()->sole();
        $payment = Payment::query()->sole();
        $before = App::getLocale();

        $km = $this->invoiceHtml($invoice, 'km');
        $this->assertStringContainsString('វិក្កយបត្រ', $km);
        $this->assertStringContainsString('Class A (សិស្ស 2 នាក់), ពី 01-11-2026 ដល់ 30-11-2026', $km);
        $this->assertStringNotContainsString('students)', $km);
        $this->assertStringNotContainsString('Bill To', $km);

        $en = $this->invoiceHtml($invoice, 'en');
        $this->assertStringContainsString('Class A (2 students), 01-11-2026 to 30-11-2026', $en);
        $this->assertStringContainsString('Bill To', $en);

        $kmReceipt = $this->receiptHtml($payment, 'km');
        foreach (['បង្កាន់ដៃ', 'ក្រុមហ៊ុន', 'វិធីទូទាត់', 'សាច់ប្រាក់', 'សរុបវិក្កយបត្រ'] as $text) {
            $this->assertStringContainsString($text, $kmReceipt);
        }
        foreach (['RECEIPT', 'Company', 'Payment Method', 'CASH', 'Invoice Total', 'Remaining Balance'] as $text) {
            $this->assertStringNotContainsString($text, $kmReceipt);
        }

        $enReceipt = $this->receiptHtml($payment, 'en');
        foreach (['RECEIPT', 'Company', 'Payment Method', 'Cash', 'Invoice Total', 'Remaining Balance'] as $text) {
            $this->assertStringContainsString($text, $enReceipt);
        }

        // A one-off print never changes the language of the rest of the request.
        $this->assertSame($before, App::getLocale());
    }

    public function test_an_enrollment_invoice_line_prints_in_khmer(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->setUpAcademicCatalog();
        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => Student::factory()->create()->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated();
        $invoice = Invoice::query()->sole();
        $stored = $invoice->items()->sole()->description;
        $this->assertStringStartsWith('Enrollment: ', $stored);

        $km = $this->invoiceHtml($invoice, 'km');
        $this->assertStringContainsString(e('ការចុះឈ្មោះ: '.substr($stored, strlen('Enrollment: '))), $km);
        $this->assertStringNotContainsString('Enrollment:', $km);
        $this->assertStringContainsString(e($stored), $this->invoiceHtml($invoice, 'en'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Mirrors InvoicePdfTest — the receipt renders through the same Browsershot
 * pipeline as the invoice now (see ReceiptPdfService's own docblock for why
 * this moved off dompdf), so it needs the same Khmer-font-wiring coverage.
 */
class ReceiptPdfTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /** Renders the Blade view directly — see InvoicePdfTest::invoiceView()'s identical reasoning. */
    private function receiptView(): string
    {
        $student = Student::factory()->create();
        $invoice = Invoice::factory()->forStudent($student)->create();
        $payment = Payment::factory()->forInvoice($invoice)->create();
        $payment->load('invoice.student');

        return View::make('pdf.receipt', [
            'payment' => $payment,
            'invoice' => $payment->invoice,
            'tenant' => $this->tenant,
            'issuerStaff' => null,
            'logoDataUri' => null,
            'stampDataUri' => null,
            'signatureDataUri' => null,
            'khmerFontRegular' => $this->khmerFontDataUri('Regular'),
            'khmerFontBold' => $this->khmerFontDataUri('Bold'),
        ])->render();
    }

    private function khmerFontDataUri(string $weight): string
    {
        return 'data:font/ttf;base64,'.base64_encode(
            file_get_contents(resource_path("fonts/khmer/NotoSansKhmer-{$weight}.ttf"))
        );
    }

    public function test_the_khmer_font_is_registered_for_the_body_and_bundled_files_exist(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $html = $this->receiptView();

        $this->assertStringContainsString("font-family: 'Noto Sans Khmer'", $html);
        $this->assertFileExists(resource_path('fonts/khmer/NotoSansKhmer-Regular.ttf'));
        $this->assertFileExists(resource_path('fonts/khmer/NotoSansKhmer-Bold.ttf'));
    }

    public function test_it_renders_the_student_and_amount(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $html = $this->receiptView();

        $this->assertStringContainsString('RECEIPT', $html);
        $this->assertStringContainsString('Student', $html);
        $this->assertStringContainsString('Invoice Total', $html);
    }

    public function test_downloading_the_receipt_pdf_produces_a_real_pdf_with_the_khmer_font_embedded(): void
    {
        // See InvoicePdfTest's identical skip — CI's runner has no Chromium.
        if (! file_exists(config('services.browsershot.chrome_path') ?: '/usr/bin/chromium')) {
            $this->markTestSkipped('Chromium is not available in this environment — see docker/php/Dockerfile.');
        }

        $this->actingAsAdminWithPermissions([Permissions::PAYMENTS_VIEW]);
        $this->tenant->update(['locale' => 'km']);
        app()->setLocale('km');

        $student = Student::factory()->create(['first_name' => 'សុខា', 'last_name' => 'ចាន់']);
        $invoice = Invoice::factory()->forStudent($student)->create();
        $payment = Payment::factory()->forInvoice($invoice)->create();

        $response = $this->get("/api/v1/payments/{$payment->id}/receipt");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('NotoSansKhmer', $pdf);
    }
}

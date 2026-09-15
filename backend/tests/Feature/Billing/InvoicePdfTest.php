<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * The invoice PDF renders through Browsershot (a system-installed headless
 * Chromium — see docker/php/Dockerfile), not dompdf: dompdf has no real
 * text-shaping engine, so Khmer content (school name, student name, notes,
 * ...) came out corrupted — dropped/misplaced characters, not just missing
 * glyphs — since it draws each codepoint's glyph without vowel reordering
 * or coeng (subscript consonant) formation. See resources/fonts/khmer and
 * pdf/invoice.blade.php's @font-face rules for the embedded font itself.
 */
class InvoicePdfTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /**
     * Renders the Blade view directly (not through InvoicePdfService's real
     * Browsershot/Chromium call) — proves the label translations and
     * @font-face wiring are correct without paying for a browser launch on
     * every test. The download endpoint test below is the one that
     * exercises the real Browsershot pipeline end to end.
     */
    private function invoiceView(): string
    {
        $student = Student::factory()->create();
        $invoice = Invoice::factory()->forStudent($student)->create();
        $invoice->load(['items.product', 'items.variant', 'student', 'payments']);

        return View::make('pdf.invoice', [
            'invoice' => $invoice,
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

    public function test_invoice_labels_render_in_english_by_default(): void
    {
        $this->actingAsAdminWithPermissions([]);
        app()->setLocale('en');

        $html = $this->invoiceView();

        $this->assertStringContainsString('INVOICE', $html);
        $this->assertStringContainsString('Bill To', $html);
        $this->assertStringContainsString('Subtotal', $html);
    }

    public function test_invoice_labels_render_in_khmer_when_the_locale_is_khmer(): void
    {
        $this->actingAsAdminWithPermissions([]);
        app()->setLocale('km');

        $html = $this->invoiceView();

        $this->assertStringContainsString('វិក្កយបត្រ', $html);
        $this->assertStringContainsString('ត្រូវទូទាត់ដោយ', $html);
        $this->assertStringContainsString('សរុបរង', $html);
    }

    public function test_the_enrollment_code_appears_below_tel_when_the_invoice_came_from_an_enrollment(): void
    {
        $this->actingAsAdminWithPermissions([]);
        app()->setLocale('en');

        $student = Student::factory()->create(['phone' => '012345678']);
        $enrollment = Enrollment::factory()->forStudent($student)->create(['enrollments_code' => 'NTS-000042-01']);
        $invoice = Invoice::factory()->forStudent($student)->create();
        InvoiceItem::factory()->forInvoice($invoice)->create([
            'reference_type' => Enrollment::class,
            'reference_id' => $enrollment->id,
        ]);
        $invoice->load(['items.product', 'items.variant', 'items.reference.schoolClass.schedules', 'student', 'payments']);

        $html = View::make('pdf.invoice', [
            'invoice' => $invoice,
            'tenant' => $this->tenant,
            'issuerStaff' => null,
            'logoDataUri' => null,
            'stampDataUri' => null,
            'signatureDataUri' => null,
            'khmerFontRegular' => $this->khmerFontDataUri('Regular'),
            'khmerFontBold' => $this->khmerFontDataUri('Bold'),
        ])->render();

        $this->assertStringContainsString('Enrollment Code', $html);
        $this->assertStringContainsString('NTS-000042-01', $html);
        $this->assertTrue(strpos($html, 'Tel') < strpos($html, 'Enrollment Code'));
    }

    public function test_no_enrollment_code_line_when_the_invoice_has_no_enrollment_reference(): void
    {
        $this->actingAsAdminWithPermissions([]);
        app()->setLocale('en');

        $html = $this->invoiceView();

        $this->assertStringNotContainsString('Enrollment Code', $html);
    }

    public function test_the_khmer_font_is_registered_for_the_body_and_bundled_files_exist(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $html = $this->invoiceView();

        $this->assertStringContainsString("font-family: 'Noto Sans Khmer'", $html);
        $this->assertFileExists(resource_path('fonts/khmer/NotoSansKhmer-Regular.ttf'));
        $this->assertFileExists(resource_path('fonts/khmer/NotoSansKhmer-Bold.ttf'));
    }

    public function test_downloading_the_invoice_pdf_produces_a_real_pdf_with_the_khmer_font_embedded(): void
    {
        // This is the one test in the file that runs the real Browsershot
        // pipeline (the other three render the Blade view directly). CI's
        // backend-tests job runs on a bare GitHub-hosted runner, not the
        // project's own Docker image, so it has no Chromium/Node/puppeteer —
        // skip there rather than fail on missing infrastructure the runner
        // was never going to have. Production deploys from the same
        // Dockerfile that installs Chromium, so this gap is CI-only.
        if (! file_exists(config('services.browsershot.chrome_path') ?: '/usr/bin/chromium')) {
            $this->markTestSkipped('Chromium is not available in this environment — see docker/php/Dockerfile.');
        }

        $this->actingAsAdminWithPermissions([Permissions::INVOICES_VIEW]);
        $this->tenant->update(['locale' => 'km']);
        app()->setLocale('km');

        $student = Student::factory()->create(['first_name' => 'សុខា', 'last_name' => 'ចាន់']);
        $invoice = Invoice::factory()->forStudent($student)->create();

        $response = $this->get("/api/v1/invoices/{$invoice->id}/pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('NotoSansKhmer', $pdf);
    }
}

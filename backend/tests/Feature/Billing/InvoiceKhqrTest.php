<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Student;
use App\Services\Billing\InvoicePdfService;
use App\Support\Authorization\Permissions;
use App\Support\Billing\Khqr;
use App\Support\Billing\KhqrImage;
use App\Support\Tenancy\TenantContext;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * The invoice PDF carries the school's KHQR (School Settings) for what is
 * still owed, so the payer scans and the amount is already filled in.
 */
class InvoiceKhqrTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    /** A real static ACLEDA KHQR (Khmer merchant name included). */
    private const TEMPLATE = '00020101021130380009khqr@aclb0111855100589230206ACLEDA391300042CCY010145204599953031165802KH5910ពៅ  សារដ្ធ6010PHNOM PENH6213020901223742263045F05';

    private const PERMS = [Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW, Permissions::INVOICES_CREATE, Permissions::PAYMENTS_CREATE];

    private function invoice(bool $qr = true): Invoice
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->setUpAcademicCatalog();
        if ($qr) {
            $this->tenant->update(['settings' => [...($this->tenant->settings ?? []), 'khqr_template' => self::TEMPLATE]]);
        }
        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => Student::factory()->create()->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated();

        return Invoice::query()->sole();
    }

    private function html(Invoice $invoice): string
    {
        // A request loads the school fresh; outside one, re-read it for the KHQR set above.
        app(TenantContext::class)->getOrFail()->refresh();

        return (new ReflectionMethod(InvoicePdfService::class, 'html'))->invoke(app(InvoicePdfService::class), $invoice->fresh(), 'en');
    }

    public function test_an_unpaid_invoice_shows_a_khqr_for_its_balance(): void
    {
        $invoice = $this->invoice();

        $html = $this->html($invoice);
        $this->assertStringContainsString('Scan to pay (KHQR)', $html);
        $this->assertStringContainsString('src="data:image/svg+xml;base64,', $html);
    }

    public function test_no_khqr_once_the_invoice_is_paid(): void
    {
        $invoice = $this->invoice();
        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => (float) $invoice->balance, 'payment_method' => 'CASH'])->assertCreated();
        $this->assertStringNotContainsString('Scan to pay', $this->html($invoice));
    }

    public function test_no_khqr_when_the_school_has_not_set_one(): void
    {
        $this->assertStringNotContainsString('Scan to pay', $this->html($this->invoice(qr: false)));
    }

    public function test_the_qr_holds_the_amount_and_keeps_the_bank_text_byte_for_byte(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('Reading a QR back needs GD.');
        }
        $payload = Khqr::withAmount(self::TEMPLATE, 210, 'USD');
        $this->assertNotNull(KhqrImage::forBalance(self::TEMPLATE, 210, 'USD'));

        // Same byte segment as KhqrImage, as a PNG the reader can open.
        $png = (new QRCode(new QROptions(['outputType' => QROutputInterface::GDIMAGE_PNG, 'outputBase64' => false, 'scale' => 6])))
            ->addByteSegment($payload)->render();
        $read = (string) (new QRCode)->readFromBlob($png);

        $this->assertSame($payload, $read);
        $this->assertStringContainsString('5406210.00', $read); // tag 54, amount
        $this->assertStringContainsString('5303840', $read); // tag 53, USD
        $this->assertStringContainsString('ពៅ  សារដ្ធ6010PHNOM PENH', $read);
    }
}

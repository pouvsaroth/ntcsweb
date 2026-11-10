<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Company;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\InvoicePdfService;
use App\Support\Authorization\Permissions;
use App\Models\DiscountCode;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * Billing > Invoices > Invoice by class: one invoice to the company paying
 * for a company class, at one total for a period, with the class's student
 * list on it. See CompanyInvoiceService.
 */
class CompanyInvoiceTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    private const PERMS = [Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW, Permissions::INVOICES_CREATE, Permissions::INVOICES_CANCEL, Permissions::PAYMENTS_CREATE];

    private SchoolClass $class;

    private Company $company;

    /** @var list<Student> */
    private array $students;

    private function companyClassWithStudents(int $count = 3): void
    {
        $this->setUpAcademicCatalog();
        $this->company = Company::factory()->create(['name' => 'ABC Company']);
        $this->class = SchoolClass::factory()->forProgram($this->computerProgram)->create(['name' => 'Class A', 'company_id' => $this->company->id]);
        $this->students = Student::factory()->count($count)->create()->all();

        $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $this->class->id,
            'course_package_id' => $this->msWordPackage->id,
            'students' => array_map(fn ($s) => ['student_id' => $s->id], $this->students),
        ])->assertCreated();
    }

    private function invoiceInput(array $overrides = []): array
    {
        return [
            'class_id' => $this->class->id,
            'invoice_date' => '2026-11-04',
            'period_from' => '2026-11-01',
            'period_to' => '2026-11-30',
            'currency' => 'USD',
            'amount' => 210,
            ...$overrides,
        ];
    }

    public function test_one_invoice_goes_to_the_company_with_the_class_total_and_student_list(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->companyClassWithStudents(3);

        $response = $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput(['discount' => 10, 'discount_reason' => 'Loyal']));

        $response->assertCreated()
            ->assertJsonPath('data.student_id', null)
            ->assertJsonPath('data.company.name', 'ABC Company')
            ->assertJsonPath('data.class.name', 'Class A')
            ->assertJsonPath('data.period_from', '2026-11-01')
            ->assertJsonPath('data.period_to', '2026-11-30')
            ->assertJsonPath('data.total', 200)
            ->assertJsonCount(3, 'data.students')
            ->assertJsonPath('data.students.0.student_code', $this->students[0]->student_code);

        $invoice = Invoice::query()->sole();
        $this->assertSame(InvoiceStatus::ISSUED, $invoice->status);
        $this->assertSame($this->msWordPackage->product_id, $invoice->items()->sole()->product_id);

        // The list finds it by the company and by a student on it.
        $this->getJson('/api/v1/invoices?search=ABC')->assertOk()->assertJsonPath('data.0.id', $invoice->id);
        $this->getJson('/api/v1/invoices?search='.urlencode($this->students[1]->student_code))->assertOk()->assertJsonPath('data.0.id', $invoice->id);
        $this->getJson("/api/v1/invoices?filter[class_id]={$this->class->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_discount_code_sets_the_discount_on_the_class_total(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->companyClassWithStudents(2);
        DiscountCode::factory()->create(['code' => 'D01', 'name' => 'New Year 10%', 'value' => 10, 'currency' => 'USD']);
        DiscountCode::factory()->create(['code' => 'OFF', 'status' => 'inactive']);

        // The dropdown with no student: every main code that works today.
        $this->getJson('/api/v1/discount-codes/available')->assertOk()->assertJsonPath('data.*.code', ['D01']);

        // The live check on the total.
        $this->postJson('/api/v1/discount-codes/check-amount', ['discount_code' => 'd01', 'amount' => 1200, 'currency' => 'USD'])
            ->assertOk()->assertJsonPath('data.amount', 120)->assertJsonPath('data.total', 1080);

        // The code sets the discount; a typed one is ignored.
        $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput(['amount' => 1200, 'discount_code' => 'D01', 'discount' => 5, 'discount_reason' => 'typed']))
            ->assertCreated()->assertJsonPath('data.total', 1080);
        $invoice = Invoice::query()->sole();
        $this->assertSame('120.00', (string) $invoice->discount);
        $this->assertSame('Code D01', $invoice->discount_reason);

        // A refused code stops the invoice.
        $invoice->update(['status' => InvoiceStatus::CANCELLED]);
        $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput(['discount_code' => 'OFF']))
            ->assertUnprocessable()->assertJsonValidationErrors('discount_code');

        // The check needs Add invoices.
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_VIEW]);
        $this->postJson('/api/v1/discount-codes/check-amount', ['discount_code' => 'D01', 'amount' => 100, 'currency' => 'USD'])->assertForbidden();
    }

    public function test_money_received_now_is_recorded_as_a_payment_with_no_student(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->companyClassWithStudents(2);

        $id = $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput(['received_amount' => 100, 'payment_method' => 'CASH']))
            ->assertCreated()->assertJsonPath('data.paid_amount', 100)->assertJsonPath('data.balance', 110)->json('data.id');

        $payment = Payment::query()->sole();
        $this->assertSame($id, $payment->invoice_id);
        $this->assertNull($payment->student_id);
        $this->assertSame(InvoiceStatus::PARTIALLY_PAID, Invoice::findOrFail($id)->status);

        // The rest is paid through the normal payment screen.
        $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => 110, 'payment_method' => 'CASH'])->assertCreated();
        $this->assertSame(InvoiceStatus::PAID, Invoice::findOrFail($id)->status);
    }

    public function test_a_class_is_invoiced_once_unless_that_invoice_is_cancelled(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->companyClassWithStudents(1);

        $id = $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput())->assertUnprocessable()->assertJsonValidationErrors('class_id');

        $this->postJson("/api/v1/invoices/{$id}/cancel", ['reason' => 'Wrong amount'])->assertOk();
        $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput(['amount' => 250]))->assertCreated()->assertJsonPath('data.total', 250);
    }

    public function test_a_class_without_a_company_or_without_students_is_refused(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->setUpAcademicCatalog();
        $empty = SchoolClass::factory()->forProgram($this->computerProgram)->create(['company_id' => Company::factory()->create()->id]);

        $this->postJson('/api/v1/invoices/company-class', [
            'class_id' => $this->computerEveningClass->id, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'currency' => 'USD', 'amount' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('class_id');

        $this->postJson('/api/v1/invoices/company-class', [
            'class_id' => $empty->id, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'currency' => 'USD', 'amount' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('class_id');

        $this->assertSame(0, Invoice::count());
    }

    public function test_the_student_list_on_the_invoice_does_not_change_when_students_leave(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->companyClassWithStudents(2);

        $id = $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput())->json('data.id');
        Enrollment::query()->where('class_id', $this->class->id)->update(['status' => Enrollment::STATUS_DROPPED]);

        $this->getJson("/api/v1/invoices/{$id}")->assertOk()->assertJsonCount(2, 'data.students');
    }

    public function test_input_is_checked(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->companyClassWithStudents(1);

        $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput(['amount' => 0, 'period_to' => '2026-10-01', 'currency' => 'EUR', 'discount' => 999, 'received_amount' => 10]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'period_to', 'currency', 'payment_method']);
    }

    public function test_taking_money_needs_the_payment_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_CREATE]);
        $this->companyClassWithStudents(1);

        $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput(['received_amount' => 50, 'payment_method' => 'CASH']))->assertForbidden();
        $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput())->assertCreated();
    }

    public function test_an_account_with_no_student_and_no_invoice_permission_cannot_open_a_company_invoice(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->companyClassWithStudents(1);
        $id = $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput(['received_amount' => 10, 'payment_method' => 'CASH']))->json('data.id');
        $paymentId = Payment::query()->sole()->id;

        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_VIEW]);
        $this->assertNull(User::query()->find(auth()->id())?->student);

        $this->getJson("/api/v1/invoices/{$id}")->assertForbidden();
        $this->getJson("/api/v1/payments/{$paymentId}")->assertForbidden();
    }

    public function test_the_invoice_pdf_shows_the_company_period_and_students(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->companyClassWithStudents(2);
        $id = $this->postJson('/api/v1/invoices/company-class', $this->invoiceInput())->json('data.id');

        // The page the PDF is printed from (the PDF itself needs Chromium).
        $html = (new ReflectionMethod(InvoicePdfService::class, 'html'))->invoke(app(InvoicePdfService::class), Invoice::findOrFail($id), 'en');

        $this->assertStringContainsString('ABC Company', $html);
        $this->assertStringContainsString('01-11-2026', $html);
        $this->assertStringContainsString('30-11-2026', $html);
        $this->assertStringContainsString('Class A', $html);
        // A company invoice with its student list prints on A4.
        $this->assertSame('A4', app(InvoicePdfService::class)->paper(Invoice::findOrFail($id)));
        foreach ($this->students as $student) {
            $this->assertStringContainsString($student->student_code, $html);
        }
    }
}

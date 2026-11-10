<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\DiscountCode;
use App\Models\DiscountCodeRedemption;
use App\Models\Invoice;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/** Using a discount code on the Enroll form and the website registration. The MS Word term fee is 24. */
class DiscountCodeUseTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions([
            Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW, Permissions::INVOICES_CANCEL,
            Permissions::ENROLLMENTS_UPDATE, Permissions::STUDENTS_APPROVE_REGISTRATION,
        ]);
        $this->setUpAcademicCatalog();
    }

    private function enroll(Student $student, ?string $code)
    {
        return $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'discount_price' => 1, // ignored once a code is given
            'discount_code' => $code,
        ]);
    }

    private function check(?Student $student, string $code)
    {
        return $this->postJson('/api/v1/discount-codes/check', [
            'discount_code' => $code,
            'student_id' => $student?->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ]);
    }

    public function test_the_dropdown_shows_only_the_students_own_codes_or_else_every_main_code(): void
    {
        $student = Student::factory()->create();
        $other = Student::factory()->create();
        $nobody = Student::factory()->create();
        $course = $this->msWordPackage->id;

        DiscountCode::factory()->create(['code' => 'PUBLIC10']);
        DiscountCode::factory()->create(['code' => 'FOREVER', 'expires_on' => null]);
        $main = DiscountCode::factory()->create(['code' => 'D01']);
        DiscountCode::factory()->create(['code' => 'S1_D01_101126', 'parent_id' => $main->id, 'audience' => 'personal'])->students()->attach($student->id);
        DiscountCode::factory()->create(['code' => 'S2_D01_101126', 'parent_id' => $main->id, 'audience' => 'personal'])->students()->attach($other->id);
        // The student's own code that no longer works is left out.
        DiscountCode::factory()->create(['code' => 'S1_OLD', 'parent_id' => $main->id, 'audience' => 'personal', 'status' => 'inactive'])->students()->attach($student->id);
        DiscountCode::factory()->create(['code' => 'EXPIRED', 'starts_on' => now()->subDays(30)->toDateString(), 'expires_on' => now()->subDay()->toDateString()]);
        DiscountCode::factory()->create(['code' => 'LATER', 'starts_on' => now()->addDays(3)->toDateString()]);
        DiscountCode::factory()->create(['code' => 'OFF', 'status' => 'inactive']);

        $codes = fn (Student $s) => $this->getJson("/api/v1/discount-codes/available?student_id={$s->id}&course_package_id={$course}")->assertOk()->json('data.*.code');
        // Has their own code: only that.
        $this->assertSame(['S1_D01_101126'], $codes($student));
        // No own code: every main code that works today.
        $this->assertSame(['D01', 'FOREVER', 'PUBLIC10'], $codes($nobody));

        $this->actingAsAdminWithPermissions([Permissions::INVOICES_VIEW]);
        $this->getJson("/api/v1/discount-codes/available?student_id={$student->id}")->assertForbidden();
        $this->actingAsAdminWithPermissions([Permissions::PAYMENTS_CREATE]);
        $this->getJson("/api/v1/discount-codes/available?student_id={$student->id}")->assertOk();
    }

    public function test_a_percent_code_takes_its_share_off_the_invoice_and_can_be_used_again(): void
    {
        DiscountCode::factory()->create(['code' => 'SAVE10', 'value' => 10, 'currency' => $this->currency()]);
        $student = Student::factory()->create();

        $this->check($student, 'save10')->assertOk()->assertJsonPath('data.amount', 2.4)->assertJsonPath('data.total', 21.6);

        $this->enroll($student, 'save10')->assertCreated();

        $invoice = Invoice::query()->where('student_id', $student->id)->sole();
        $this->assertSame('2.40', (string) $invoice->discount);
        $this->assertSame('Code SAVE10', $invoice->discount_reason);
        $this->assertSame('21.60', (string) $invoice->total);
        $this->assertSame(1, DiscountCodeRedemption::query()->where('status', 'applied')->count());

        // The same student can use it again on another enrollment.
        $this->check($student, 'SAVE10')->assertOk();

        // Cancelling the invoice frees the use.
        $this->postJson("/api/v1/invoices/{$invoice->id}/cancel", ['reason' => 'Wrong course'])->assertOk();
        $this->assertSame(0, DiscountCodeRedemption::query()->where('status', 'applied')->count());
        $this->check($student, 'SAVE10')->assertOk();
    }

    public function test_an_amount_code_with_a_cap_and_the_rules_that_refuse_a_code(): void
    {
        $student = Student::factory()->create();
        $other = Student::factory()->create();
        $currency = $this->currency();

        DiscountCode::factory()->create(['code' => 'FIVE', 'discount_type' => 'amount', 'value' => 5, 'currency' => $currency]);
        $this->check($student, 'FIVE')->assertOk()->assertJsonPath('data.amount', 5);

        DiscountCode::factory()->create(['code' => 'HALF', 'value' => 50, 'max_discount' => 10, 'currency' => $currency]);
        $this->check($student, 'HALF')->assertOk()->assertJsonPath('data.amount', 10);

        $message = fn (string $code) => $this->check($student, $code)->assertUnprocessable()->json('errors.discount_code.0');

        $this->assertSame('This discount code does not exist.', $message('NOPE'));

        DiscountCode::factory()->create(['code' => 'OLD', 'starts_on' => now()->subMonth(), 'expires_on' => now()->subDays(2), 'currency' => $currency]);
        $this->assertStringStartsWith('This discount code expired on', $message('OLD'));

        DiscountCode::factory()->create(['code' => 'SOON', 'starts_on' => now()->addDays(3), 'expires_on' => now()->addMonth(), 'currency' => $currency]);
        $this->assertStringStartsWith('This discount code can be used from', $message('SOON'));

        DiscountCode::factory()->create(['code' => 'OFF', 'status' => 'inactive', 'currency' => $currency]);
        $this->assertSame('This discount code is not active.', $message('OFF'));

        // No end date: always works once started.
        DiscountCode::factory()->create(['code' => 'FOREVER', 'expires_on' => null, 'currency' => $currency]);
        $this->check($student, 'FOREVER')->assertOk();

        $vip = DiscountCode::factory()->create(['code' => 'VIP', 'audience' => 'personal', 'currency' => $currency]);
        $vip->students()->attach($other->id);
        $this->assertSame('This discount code is not for this student.', $message('VIP'));
        $this->check($other, 'VIP')->assertOk();

        // A student with their own code uses only that one.
        $this->assertSame('This student has their own discount code. Use one of those.', $this->check($other, 'FIVE')->assertUnprocessable()->json('errors.discount_code.0'));

        // A refused code stops the enrollment.
        $this->enroll($student, 'OFF')->assertUnprocessable()->assertJsonValidationErrors('discount_code');
        $this->assertSame(0, Invoice::query()->where('student_id', $student->id)->count());
    }

    public function test_the_website_registration_takes_a_public_code_and_rejecting_frees_it(): void
    {
        DiscountCode::factory()->create(['code' => 'WEB5', 'discount_type' => 'amount', 'value' => 5, 'currency' => $this->currency()]);
        $vip = DiscountCode::factory()->create(['code' => 'VIP', 'audience' => 'personal', 'currency' => $this->currency()]);
        $vip->students()->attach(Student::factory()->create()->id);

        $public = fn (array $data) => $this->withHeader('X-Tenant', $this->tenant->slug)->postJson('/api/v1/public/discount-codes/check', [
            'course_package_id' => $this->msWordPackage->id, 'fee_type' => 'term', ...$data,
        ]);
        $public(['discount_code' => 'web5'])->assertOk()->assertJsonPath('data.total', 19);
        $public(['discount_code' => 'VIP'])->assertUnprocessable()->assertJsonPath('errors.discount_code.0', 'This discount code is not for this student.');

        $this->withHeader('X-Tenant', $this->tenant->slug)->postJson('/api/v1/public/student-registrations', [
            'first_name' => 'Sok', 'last_name' => 'Dara', 'phone' => '012345678',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'class_id' => $this->computerEveningClass->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'payment_method' => 'CASH', 'discount_code' => 'WEB5',
        ])->assertCreated();

        $student = Student::query()->where('first_name', 'Sok')->sole();
        $invoice = $student->invoices()->sole();
        $this->assertSame('19.00', (string) $invoice->balance);
        $this->assertSame('Code WEB5', $invoice->discount_reason);

        $this->postJson("/api/v1/student-registrations/{$student->id}/reject", ['reason' => 'Duplicate'])->assertOk();
        $this->assertSame(0, DiscountCodeRedemption::query()->where('status', 'applied')->count());
    }

    public function test_record_payment_takes_a_code_off_what_is_still_owed(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW, Permissions::INVOICES_CANCEL, Permissions::PAYMENTS_CREATE,
        ]);
        DiscountCode::factory()->create(['code' => 'SAVE10', 'value' => 10, 'currency' => $this->currency()]);
        $student = Student::factory()->create();
        $this->enroll($student, null)->assertCreated(); // 24 - 1 discount = 23 owed
        $invoice = Invoice::query()->where('student_id', $student->id)->sole();
        $this->assertSame('23.00', (string) $invoice->balance);

        // The check: 10% of what is still owed.
        $this->postJson('/api/v1/discount-codes/check-invoice', ['discount_code' => 'save10', 'invoice_id' => $invoice->id])
            ->assertOk()->assertJsonPath('data.amount', 2.3)->assertJsonPath('data.total', 20.7);

        // The code sets the discount; a typed discount is ignored.
        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 20.7, 'payment_method' => 'CASH', 'discount_code' => 'SAVE10', 'discount' => 9, 'discount_reason' => 'typed',
        ])->assertCreated();

        $invoice->refresh();
        $this->assertSame('3.30', (string) $invoice->discount);
        $this->assertSame('Code SAVE10', $invoice->discount_reason);
        $this->assertSame('0.00', (string) $invoice->balance);
        $this->assertSame(1, DiscountCodeRedemption::query()->where('invoice_id', $invoice->id)->where('status', 'applied')->count());

        // A refused code stops the payment.
        $this->enroll($other = Student::factory()->create(), null)->assertCreated();
        $otherInvoice = Invoice::query()->where('student_id', $other->id)->sole();
        $this->postJson("/api/v1/invoices/{$otherInvoice->id}/payments", ['amount' => 5, 'payment_method' => 'CASH', 'discount_code' => 'NOPE'])
            ->assertUnprocessable()->assertJsonValidationErrors('discount_code');

        // The check needs Add payments.
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_VIEW]);
        $this->postJson('/api/v1/discount-codes/check-invoice', ['discount_code' => 'SAVE10', 'invoice_id' => $invoice->id])->assertForbidden();
    }

    private function currency(): string
    {
        return $this->tenant->fresh()->default_currency;
    }
}

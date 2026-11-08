<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\DiscountCode;
use App\Models\DiscountCodeRedemption;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/** Student portal > My discount codes (step 3). The MS Word term fee is 24. */
class MyDiscountCodeTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    private Student $student;

    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions([
            Permissions::ENROLLMENTS_CREATE, Permissions::DISCOUNT_CODES_CREATE, Permissions::DISCOUNT_CODES_UPDATE, Permissions::DISCOUNT_CODES_VIEW,
        ]);
        $this->setUpAcademicCatalog();
        $this->studentUser = User::factory()->forTenant($this->tenant)->create();
        $this->student = Student::factory()->create(['user_id' => $this->studentUser->id]);
    }

    private function currency(): string
    {
        return $this->tenant->fresh()->default_currency;
    }

    /** Enrolled by staff with nothing paid: an unpaid course invoice. */
    private function unpaidInvoice(): Invoice
    {
        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $this->student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'received_amount' => 0,
        ])->assertCreated();

        return Invoice::query()->where('student_id', $this->student->id)->sole();
    }

    public function test_giving_a_personal_code_notifies_the_student_and_they_see_it(): void
    {
        $this->postJson('/api/v1/discount-codes', [
            'code' => 'VIP20', 'name' => 'VIP', 'audience' => 'personal', 'discount_type' => 'percent', 'value' => 20,
            'starts_on' => now()->toDateString(), 'expires_on' => now()->addMonth()->toDateString(),
            'student_ids' => [$this->student->id],
        ])->assertCreated();

        $note = UserNotification::query()->where('recipient_id', $this->studentUser->id)->where('type', NotificationType::DISCOUNT_CODE_GIVEN)->sole();
        $this->assertSame('VIP20', $note->data['code']);
        $this->assertSame('20%', $note->data['discount']);

        DiscountCode::factory()->create(['code' => 'PUBLIC5', 'currency' => $this->currency()]);
        DiscountCode::factory()->create(['code' => 'EXPIRED', 'starts_on' => now()->subMonth(), 'expires_on' => now()->subDay(), 'currency' => $this->currency()]);
        $someoneElse = DiscountCode::factory()->create(['code' => 'OTHERVIP', 'audience' => 'personal', 'currency' => $this->currency()]);
        $someoneElse->students()->attach(Student::factory()->create()->id);

        $this->actingAsTenantUser($this->studentUser);
        $codes = collect($this->getJson('/api/v1/my-discount-codes')->assertOk()->json('data.codes'))->pluck('state', 'code');

        $this->assertEqualsCanonicalizing(['VIP20' => 'active', 'PUBLIC5' => 'active'], $codes->all());
    }

    public function test_a_student_puts_a_code_on_their_unpaid_invoice_once(): void
    {
        $invoice = $this->unpaidInvoice();
        DiscountCode::factory()->create(['code' => 'SAVE10', 'value' => 10, 'currency' => $this->currency()]);

        $this->actingAsTenantUser($this->studentUser);
        $this->getJson('/api/v1/my-discount-codes')->assertOk()->assertJsonPath('data.invoices.0.id', $invoice->id);

        $this->postJson("/api/v1/my-invoices/{$invoice->id}/discount-code", ['discount_code' => 'save10'])
            ->assertOk()
            ->assertJsonPath('data.discount_reason', 'Code SAVE10');

        $invoice->refresh();
        $this->assertSame('2.40', (string) $invoice->discount);
        $this->assertSame('21.60', (string) $invoice->balance);
        $this->assertSame(1, DiscountCodeRedemption::query()->where('student_id', $this->student->id)->count());

        // The invoice now has a discount: no second code, and it leaves the list.
        DiscountCode::factory()->create(['code' => 'MORE', 'currency' => $this->currency()]);
        $this->postJson("/api/v1/my-invoices/{$invoice->id}/discount-code", ['discount_code' => 'MORE'])->assertUnprocessable();
        $this->getJson('/api/v1/my-discount-codes')->assertOk()->assertJsonCount(0, 'data.invoices')
            ->assertJsonPath('data.codes.1.state', 'used');
    }

    public function test_a_student_cannot_touch_another_students_invoice(): void
    {
        $invoice = $this->unpaidInvoice();
        DiscountCode::factory()->create(['code' => 'SAVE10', 'currency' => $this->currency()]);

        $otherUser = User::factory()->forTenant($this->tenant)->create();
        Student::factory()->create(['user_id' => $otherUser->id]);
        $this->actingAsTenantUser($otherUser);

        $this->postJson("/api/v1/my-invoices/{$invoice->id}/discount-code", ['discount_code' => 'SAVE10'])->assertNotFound();
    }
}

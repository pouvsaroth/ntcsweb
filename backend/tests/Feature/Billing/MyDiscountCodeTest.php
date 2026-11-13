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

    public function test_assigning_a_code_notifies_the_student_and_they_see_only_their_own(): void
    {
        DiscountCode::factory()->create(['code' => 'PUBLIC5', 'currency' => $this->currency()]);
        DiscountCode::factory()->create(['code' => 'EXPIRED', 'starts_on' => now()->subMonth(), 'expires_on' => now()->subDay(), 'currency' => $this->currency()]);

        // No code given yet: main codes are not shown to students.
        $this->actingAsTenantUser($this->studentUser);
        $this->getJson('/api/v1/my-discount-codes')->assertOk()->assertJsonCount(0, 'data.codes');

        $this->actingAsAdminWithPermissions([Permissions::DISCOUNT_CODES_VIEW, Permissions::DISCOUNT_CODES_CREATE, Permissions::DISCOUNT_CODES_UPDATE]);
        $main = DiscountCode::factory()->create(['code' => 'VIP20', 'value' => 20, 'currency' => $this->currency()]);
        $own = $this->postJson("/api/v1/discount-codes/{$main->id}/students", ['student_ids' => [$this->student->id]])->assertOk()->json('meta.codes.0');

        $note = UserNotification::query()->where('recipient_id', $this->studentUser->id)->where('type', NotificationType::DISCOUNT_CODE_GIVEN)->sole();
        $this->assertSame($own, $note->data['code']);
        $this->assertSame('20%', $note->data['discount']);

        $someoneElse = DiscountCode::factory()->create(['code' => 'OTHERVIP', 'audience' => 'personal', 'currency' => $this->currency()]);
        $someoneElse->students()->attach(Student::factory()->create()->id);

        // Now only their own code.
        $this->actingAsTenantUser($this->studentUser);
        $codes = collect($this->getJson('/api/v1/my-discount-codes')->assertOk()->json('data.codes'))->pluck('state', 'code');
        $this->assertSame([$own => 'active'], $codes->all());
    }

    public function test_a_student_puts_a_code_on_their_unpaid_invoice_once(): void
    {
        $invoice = $this->unpaidInvoice();
        DiscountCode::factory()->create(['code' => 'MAIN10', 'value' => 10, 'currency' => $this->currency()]);
        $mine = DiscountCode::factory()->create(['code' => 'SAVE10', 'value' => 10, 'audience' => 'personal', 'currency' => $this->currency()]);
        $mine->students()->attach($this->student->id);

        $this->actingAsTenantUser($this->studentUser);
        $this->getJson('/api/v1/my-discount-codes')->assertOk()->assertJsonPath('data.invoices.0.id', $invoice->id);

        // A main code they were not given is refused.
        $this->postJson("/api/v1/my-invoices/{$invoice->id}/discount-code", ['discount_code' => 'MAIN10'])
            ->assertUnprocessable()->assertJsonValidationErrors('discount_code');
        $this->assertSame('0.00', (string) $invoice->fresh()->discount);

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
            ->assertJsonCount(1, 'data.codes')->assertJsonPath('data.codes.0.used_amount', 2.4);
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

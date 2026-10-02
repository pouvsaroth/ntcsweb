<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Approving an exam application records its fee as a paid Cash invoice +
 * payment — see ExamApplicationService::recordFeeOnApproval().
 */
class ExamApplicationAutoPaymentTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function application(array $attributes = []): ExamApplication
    {
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->forClass(SchoolClass::factory()->create())->forStudent($student)->create();

        return ExamApplication::factory()->forStudent($student)->forEnrollment($enrollment)->create($attributes);
    }

    private function paymentFor(ExamApplication $application): ?Payment
    {
        $invoice = Invoice::query()
            ->where('student_id', $application->student_id)
            ->whereHas('items', fn ($q) => $q->where('reference_type', ExamApplication::class)->where('reference_id', $application->id))
            ->first();

        return $invoice !== null ? Payment::query()->where('invoice_id', $invoice->id)->first() : null;
    }

    public function test_approving_records_the_application_fee_as_a_cash_payment(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_APPROVE]);
        $application = $this->application(['fee_amount' => 25, 'fee_currency' => 'USD']);

        $this->postJson("/api/v1/exam-applications/{$application->id}/approve")->assertOk();

        $payment = $this->paymentFor($application);
        $this->assertNotNull($payment);
        $this->assertSame('25.00', (string) $payment->amount);
        $this->assertSame(PaymentMethod::CASH, $payment->payment_method);
        $this->assertSame(now()->toDateString(), $payment->payment_date->toDateString());
        $this->assertSame($admin->id, $payment->received_by);
        $this->assertNotNull($application->fresh()->sold_at);
    }

    public function test_quick_approving_from_the_exams_tab_also_records_it_once(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $application = $this->application(['status' => ExamApplication::STATUS_DRAFT, 'fee_amount' => 30]);

        $this->putJson("/api/v1/exam-applications/{$application->id}", ['status' => ExamApplication::STATUS_APPROVED])->assertOk();
        // Saving the approved application again must not charge twice.
        $this->putJson("/api/v1/exam-applications/{$application->id}", ['status' => ExamApplication::STATUS_APPROVED, 'remark' => 'Room changed'])->assertOk();

        $this->assertSame('30.00', (string) $this->paymentFor($application)->amount);
        $this->assertSame(1, Invoice::query()->where('student_id', $application->student_id)->count());
    }

    public function test_an_application_without_its_own_fee_uses_the_schools_exam_fee(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_APPROVE]);
        $this->tenant->update(['exam_fee_amount' => 18]);
        $this->actingAsTenantUser($admin->fresh()); // the request resolves the school through the user
        $application = $this->application(['fee_amount' => null, 'fee_currency' => null]);

        $this->postJson("/api/v1/exam-applications/{$application->id}/approve")->assertOk();

        $this->assertSame('18.00', (string) $this->paymentFor($application)->amount);
    }

    public function test_no_payment_when_the_fee_was_already_taken_or_none_is_set(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_APPROVE]);
        $alreadySold = $this->application(['sold_at' => now()]);
        $this->tenant->update(['exam_fee_amount' => null]);
        $noFee = $this->application(['fee_amount' => null, 'fee_currency' => null]);

        $this->postJson("/api/v1/exam-applications/{$alreadySold->id}/approve")->assertOk();
        $this->postJson("/api/v1/exam-applications/{$noFee->id}/approve")->assertOk();

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(ExamApplication::STATUS_APPROVED, $noFee->fresh()->status);
    }

    public function test_rejecting_records_nothing(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_REJECT]);
        $application = $this->application();

        $this->postJson("/api/v1/exam-applications/{$application->id}/reject", ['reason' => 'Not eligible'])->assertOk();

        $this->assertSame(0, Invoice::query()->count());
    }
}

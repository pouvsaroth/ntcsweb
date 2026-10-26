<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Platform\Models\BillingSettings;
use App\Support\Authorization\Permissions;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * The exam fee paid by the student: cash at school, or the school's QR with
 * the amount — found through Bakong (faked here), like a plan sign-up.
 */
class ExamFeePaymentTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const TEMPLATE = '00020101021129380009khqr@aclb0111855100589230206ACLEDA391300042CCY010145204599953031165802KH5910ពៅ  សារដ្ធ6010PHNOM PENH621302090122374226304D1E3';

    private const BAKONG = 'https://api-bakong.nbc.gov.kh/v1/check_transaction_by_md5';

    private User $studentUser;

    private ExamApplication $draft;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-27 03:00:00', 'UTC'));
        config(['platform.bakong.api_url' => 'https://api-bakong.nbc.gov.kh']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function school(bool $qr = true): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW, Permissions::EXAM_APPLICATIONS_UPDATE]);
        $this->tenant->update([
            'exam_fee_amount' => 25,
            'settings' => [...($this->tenant->settings ?? []), ...($qr ? ['khqr_template' => self::TEMPLATE] : [])],
        ]);
        BillingSettings::current()->update(['khqr_template' => self::TEMPLATE, 'bakong_token' => 'test-token']);

        $this->studentUser = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $this->studentUser->id]);
        $enrollment = Enrollment::factory()->forClass(SchoolClass::factory()->create())->forStudent($student)->create();
        $this->draft = ExamApplication::factory()->forStudent($student)->forEnrollment($enrollment)->create([
            'exam_date' => null, 'status' => ExamApplication::STATUS_DRAFT,
        ]);
    }

    private function apply(string $method): int
    {
        return $this->postJson('/api/v1/my-exam-applications', [
            'enrollment_id' => $this->draft->enrollment_id, 'first_name' => 'Test', 'last_name' => 'Student', 'payment_method' => $method,
        ])->assertCreated()->assertJsonPath('data.payment_method', $method)->json('data.id');
    }

    private function bakong(bool $paid, float $amount = 25.0): void
    {
        Http::fake([self::BAKONG => $paid
            ? Http::response(['responseCode' => 0, 'data' => ['hash' => 'hash-1', 'currency' => 'USD', 'amount' => $amount]])
            : Http::response(['responseCode' => 1, 'data' => null])]);
    }

    public function test_paying_by_qr_is_found_through_bakong_and_shows_paid(): void
    {
        $this->school();
        $this->actingAsTenantUser($this->studentUser);
        $this->assertTrue($this->getJson('/api/v1/my-exam-applications/fee')->assertOk()->json('data.qr_available'));
        $id = $this->apply('qr');

        $qr = $this->postJson("/api/v1/my-exam-applications/{$id}/qr")->assertOk()->json('data');
        $this->assertEquals([25, 'USD'], [$qr['amount'], $qr['currency']]);
        $this->assertStringContainsString('540525.00', $qr['khqr']);
        // The same code while it is still valid.
        $this->assertSame($qr['khqr'], $this->postJson("/api/v1/my-exam-applications/{$id}/qr")->json('data.khqr'));

        // Bakong: not yet, then paid.
        Http::fake([self::BAKONG => Http::sequence()
            ->push(['responseCode' => 1, 'data' => null])
            ->push(['responseCode' => 0, 'data' => ['hash' => 'hash-1', 'currency' => 'USD', 'amount' => 25]])]);
        $this->getJson("/api/v1/my-exam-applications/{$id}/payment")->assertOk()->assertJsonPath('data.paid', false);
        // Asked again within a few seconds: Bakong is not asked twice.
        $this->getJson("/api/v1/my-exam-applications/{$id}/payment")->assertOk()->assertJsonPath('data.paid', false);
        Http::assertSentCount(1);

        Carbon::setTestNow(now()->addSeconds(10));
        $this->getJson("/api/v1/my-exam-applications/{$id}/payment")->assertOk()->assertJsonPath('data.paid', true);
        $this->assertSame('hash-1', ExamApplication::query()->find($id)->bakong_hash);

        // Paid: no new QR.
        $this->postJson("/api/v1/my-exam-applications/{$id}/qr")->assertUnprocessable();

        // The approver sees it paid; Print records it as a QR payment.
        $this->actingAsTenantUser($this->admin);
        $row = collect($this->getJson('/api/v1/exam-applications?approval_queue=1')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame('qr', $row['payment_method']);
        $this->assertNotNull($row['paid_at']);
        $this->postJson("/api/v1/exam-applications/{$id}/print", ['fee' => 25, 'currency' => 'USD', 'payment_method' => PaymentMethod::CASH, 'print_date' => '2026-10-27'])->assertOk();
        $this->assertSame(PaymentMethod::QR, Payment::query()->latest('id')->value('payment_method'));
    }

    public function test_pay_later_and_cash_stay_not_paid(): void
    {
        $this->school();
        $this->actingAsTenantUser($this->studentUser);
        $id = $this->apply('cash');

        $row = $this->getJson('/api/v1/my-exam-applications')->assertOk()->json('data.0');
        $this->assertSame(['cash', null], [$row['payment_method'], $row['paid_at']]);

        // A wrong amount at Bakong is not accepted as paid.
        $this->postJson("/api/v1/my-exam-applications/{$id}/qr")->assertOk();
        $this->bakong(true, 5.0);
        $this->getJson("/api/v1/my-exam-applications/{$id}/payment")->assertOk()->assertJsonPath('data.paid', false);
    }

    public function test_no_qr_without_the_schools_code_and_only_for_own_applications(): void
    {
        $this->school(qr: false);
        $this->actingAsTenantUser($this->studentUser);
        $this->assertFalse($this->getJson('/api/v1/my-exam-applications/fee')->json('data.qr_available'));
        $id = $this->apply('qr');
        $this->postJson("/api/v1/my-exam-applications/{$id}/qr")->assertUnprocessable()->assertJsonValidationErrors('payment');

        $other = User::factory()->forTenant($this->tenant)->create();
        Student::factory()->create(['user_id' => $other->id]);
        $this->actingAsTenantUser($other);
        $this->postJson("/api/v1/my-exam-applications/{$id}/qr")->assertNotFound();
    }
}

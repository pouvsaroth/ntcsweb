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
 * The exam fee: students pay cash at school (the QR option was removed). A
 * QR already shown before the removal is still confirmed through Bakong
 * (faked here) when the list is opened.
 */
class ExamFeePaymentTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

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

    private function school(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW, Permissions::EXAM_APPLICATIONS_UPDATE]);
        $this->tenant->update(['exam_fee_amount' => 25]);
        BillingSettings::current()->update(['khqr_template' => '00020101021129380009khqr@aclb5802KH6304ABCD', 'bakong_token' => 'test-token']);

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

    public function test_cash_is_the_only_way_to_apply(): void
    {
        $this->school();
        $this->actingAsTenantUser($this->studentUser);
        $this->assertArrayNotHasKey('qr_available', $this->getJson('/api/v1/my-exam-applications/fee')->assertOk()->json('data'));

        $this->postJson('/api/v1/my-exam-applications', [
            'enrollment_id' => $this->draft->enrollment_id, 'first_name' => 'Test', 'last_name' => 'Student', 'payment_method' => 'qr',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $id = $this->apply('cash');
        $row = $this->getJson('/api/v1/my-exam-applications')->assertOk()->json('data.0');
        $this->assertSame(['cash', null], [$row['payment_method'], $row['paid_at']]);
        $this->postJson("/api/v1/my-exam-applications/{$id}/qr")->assertNotFound();
    }

    public function test_a_qr_shown_before_the_removal_is_still_confirmed(): void
    {
        $this->school();
        $this->actingAsTenantUser($this->studentUser);
        $id = $this->apply('cash');
        ExamApplication::query()->whereKey($id)->update([
            'payment_method' => 'qr', 'fee_amount' => 25, 'fee_currency' => 'USD',
            'khqr' => 'old-code', 'khqr_md5' => md5('old-code'), 'khqr_expires_at' => now()->addMinutes(5),
        ]);

        // A wrong amount first (not accepted), then the right one.
        Http::fake([self::BAKONG => Http::sequence()
            ->push(['responseCode' => 0, 'data' => ['hash' => 'hash-0', 'currency' => 'USD', 'amount' => 5]])
            ->push(['responseCode' => 0, 'data' => ['hash' => 'hash-1', 'currency' => 'USD', 'amount' => 25]])]);
        $this->assertNull($this->getJson('/api/v1/my-exam-applications')->assertOk()->json('data.0.paid_at'));

        Carbon::setTestNow(now()->addSeconds(10));
        $this->assertNotNull($this->getJson('/api/v1/my-exam-applications')->assertOk()->json('data.0.paid_at'));

        $this->actingAsTenantUser($this->admin);
        $this->postJson("/api/v1/exam-applications/{$id}/print", ['fee' => 25, 'currency' => 'USD', 'payment_method' => PaymentMethod::CASH, 'print_date' => '2026-10-27'])->assertOk();
        $this->assertSame(PaymentMethod::QR, Payment::query()->latest('id')->value('payment_method'));
    }
}

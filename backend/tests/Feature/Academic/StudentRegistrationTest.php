<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Academic\StudentRegistrationApprovedNotification;
use App\Support\Authorization\Permissions;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * The public self-registration wizard's backend contract: a visitor submits
 * the form unauthenticated -> lands in Student::STATUS_PENDING with an
 * unpaid invoice and cannot log in -> an admin approves (recording the cash
 * payment and activating both rows) or rejects. See
 * StudentRegistrationService.
 */
class StudentRegistrationTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Sok',
            'last_name' => 'Dara',
            'phone' => '012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
            'payment_method' => 'CASH',
        ], $overrides);
    }

    public function test_a_visitor_can_self_register_and_lands_in_the_pending_queue_unpaid(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/public/student-registrations', $this->registrationPayload());

        $response->assertCreated();

        $student = Student::where('first_name', 'Sok')->firstOrFail();
        $this->assertSame(Student::STATUS_PENDING, $student->status);
        $this->assertSame(User::STATUS_PENDING_APPROVAL, $student->user->status);
        $this->assertTrue(Hash::check('password123', $student->user->password));
        $this->assertTrue($student->user->hasRole(Role::STUDENT));

        $enrollment = $student->enrollments()->firstOrFail();
        $this->assertSame('24.00', (string) $enrollment->fee);

        $invoice = $student->invoices()->firstOrFail();
        $this->assertSame('24.00', (string) $invoice->balance);
        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_a_fee_type_the_package_does_not_offer_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/public/student-registrations', $this->registrationPayload(['fee_type' => 'video_2']));

        $response->assertUnprocessable();
    }

    public function test_a_pending_registrant_cannot_log_in_yet(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/public/student-registrations', $this->registrationPayload())
            ->assertCreated();

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)->postJson('/api/v1/auth/login', [
            'login' => '012345678',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('login');
    }

    public function test_approving_records_the_cash_payment_and_activates_the_account(): void
    {
        Notification::fake();

        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_APPROVE_REGISTRATION]);
        $this->setUpAcademicCatalog();

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/public/student-registrations', $this->registrationPayload())
            ->assertCreated();

        $student = Student::where('first_name', 'Sok')->firstOrFail();

        $response = $this->postJson("/api/v1/student-registrations/{$student->id}/approve");
        $response->assertOk();
        $response->assertJsonPath('data.status', Student::STATUS_ACTIVE);

        $student->refresh();
        $this->assertSame(Student::STATUS_ACTIVE, $student->status);
        $this->assertSame(User::STATUS_ACTIVE, $student->user->status);

        $invoice = $student->invoices()->firstOrFail();
        $this->assertSame('0.00', (string) $invoice->balance);
        $this->assertSame('24.00', (string) $invoice->paid_amount);

        Notification::assertSentTo($student->user, StudentRegistrationApprovedNotification::class);
    }

    public function test_a_pending_registrant_can_log_in_once_approved(): void
    {
        Notification::fake();

        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_APPROVE_REGISTRATION]);
        $this->setUpAcademicCatalog();

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/public/student-registrations', $this->registrationPayload())
            ->assertCreated();

        $student = Student::where('first_name', 'Sok')->firstOrFail();
        $this->postJson("/api/v1/student-registrations/{$student->id}/approve")->assertOk();

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)->postJson('/api/v1/auth/login', [
            'login' => '012345678',
            'password' => 'password123',
            'device_name' => 'test-device',
        ]);

        $response->assertOk();
    }

    public function test_rejecting_records_the_reason_and_suspends_the_account(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_APPROVE_REGISTRATION]);
        $this->setUpAcademicCatalog();

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/public/student-registrations', $this->registrationPayload())
            ->assertCreated();

        $student = Student::where('first_name', 'Sok')->firstOrFail();

        $response = $this->postJson("/api/v1/student-registrations/{$student->id}/reject", [
            'reason' => 'Could not verify the phone number.',
        ]);
        $response->assertOk();

        $student->refresh();
        $this->assertSame(Student::STATUS_INACTIVE, $student->status);
        $this->assertSame(User::STATUS_SUSPENDED, $student->user->status);

        $invoice = $student->invoices()->firstOrFail();
        $this->assertSame('24.00', (string) $invoice->balance, 'A rejected registration is never charged.');
    }

    public function test_a_qr_registration_is_approved_with_the_qr_payment_method_not_cash(): void
    {
        Notification::fake();

        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_APPROVE_REGISTRATION]);
        $this->setUpAcademicCatalog();

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/public/student-registrations', $this->registrationPayload(['payment_method' => 'QR']))
            ->assertCreated();

        $student = Student::where('first_name', 'Sok')->firstOrFail();
        $invoice = $student->invoices()->firstOrFail();
        $this->assertSame('QR', $invoice->intended_payment_method);

        $this->postJson("/api/v1/student-registrations/{$student->id}/approve")->assertOk();

        $payment = $invoice->payments()->firstOrFail();
        $this->assertSame(PaymentMethod::QR, $payment->payment_method);
    }

    public function test_approving_requires_the_approve_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        $this->setUpAcademicCatalog();

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/public/student-registrations', $this->registrationPayload())
            ->assertCreated();

        $student = Student::where('first_name', 'Sok')->firstOrFail();

        $this->postJson("/api/v1/student-registrations/{$student->id}/approve")->assertForbidden();
    }
}

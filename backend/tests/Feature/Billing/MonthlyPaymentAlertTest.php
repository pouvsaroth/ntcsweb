<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Student;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * The exact scenario from the feature request: a student enrolled 18-Aug on
 * a 3-day alert window shows up once "today" reaches 15-Sep (3 days before
 * their 18-Sep next payment) — both for the admin dashboard widget and for
 * the student's own payment-due popup.
 */
class MonthlyPaymentAlertTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_the_dashboard_widget_lists_a_student_once_within_the_alert_window(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW]);
        $this->admin->tenant->update(['settings' => ['monthly_payment_alert_days' => 3]]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => '2026-08-18',
            'fee_type' => 'monthly',
        ])->assertCreated();

        // Two days too early — 16 days until the 18-Sep next payment.
        $this->travelTo(Carbon::parse('2026-09-02'));
        $this->getJson('/api/v1/monthly-payment-alerts')->assertOk()->assertJsonCount(0, 'data');

        // Exactly 3 days before the 18-Sep next payment — the request's own example.
        $this->travelTo(Carbon::parse('2026-09-15'));
        $response = $this->getJson('/api/v1/monthly-payment-alerts');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.student.id', $student->id);
        $response->assertJsonPath('data.0.next_payment_date', '2026-09-18');
    }

    public function test_a_student_sees_their_own_upcoming_payment_in_the_popup_alert(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->admin->tenant->update(['settings' => ['monthly_payment_alert_days' => 3]]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => '2026-08-18',
            'fee_type' => 'monthly',
        ])->assertCreated();

        $studentUser = User::factory()->forTenant($this->tenant)->create();
        $student->forceFill(['user_id' => $studentUser->id])->save();

        $this->travelTo(Carbon::parse('2026-09-15'));
        $this->actingAsTenantUser($studentUser);
        $response = $this->getJson('/api/v1/my-monthly-payment-alerts');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.course', 'MS Word 2024');
        $response->assertJsonPath('data.0.next_payment_date', '2026-09-18');
    }

    public function test_a_student_never_sees_another_students_payment_alert(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->admin->tenant->update(['settings' => ['monthly_payment_alert_days' => 3]]);
        $this->setUpAcademicCatalog();
        $otherStudent = Student::factory()->create();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $otherStudent->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => '2026-08-18',
            'fee_type' => 'monthly',
        ])->assertCreated();

        $unrelatedStudent = Student::factory()->create();
        $studentUser = User::factory()->forTenant($this->tenant)->create();
        $unrelatedStudent->forceFill(['user_id' => $studentUser->id])->save();

        $this->travelTo(Carbon::parse('2026-09-15'));
        $this->actingAsTenantUser($studentUser);
        $response = $this->getJson('/api/v1/my-monthly-payment-alerts');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }
}

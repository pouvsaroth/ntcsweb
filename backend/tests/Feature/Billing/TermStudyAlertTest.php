<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Enrollment;
use App\Models\Student;
use App\Services\Billing\InvoiceService;
use App\Support\Authorization\Permissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * Dashboard list: Studying students billed by term whose enroll date is more
 * than the school's term_study_alert_days (default 31) before today.
 */
class TermStudyAlertTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    private function enroll(Student $student, string $feeType, string $date): int
    {
        return $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => $date,
            'fee_type' => $feeType,
        ])->assertCreated()->json('data.id');
    }

    public function test_lists_term_students_past_the_default_31_days_only(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW]);
        $this->setUpAcademicCatalog();
        $term = Student::factory()->create();
        $termOnline = Student::factory()->create();
        $monthly = Student::factory()->create();
        $recent = Student::factory()->create();

        $this->enroll($term, 'term', '2026-08-01');
        $this->enroll($termOnline, 'term_online', '2026-08-10');
        $this->enroll($monthly, 'monthly', '2026-08-01');
        $this->enroll($recent, 'term', '2026-08-25');

        // 2026-09-10: term = 40 days, term_online = 31 (not over), recent = 16.
        $this->travelTo(Carbon::parse('2026-09-10 09:00'));
        $response = $this->getJson('/api/v1/term-study-alerts')->assertOk();
        $response->assertJsonPath('data.days', 31);
        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.student.id', $term->id);
        $response->assertJsonPath('data.items.0.days', 40);
        $response->assertJsonPath('data.items.0.type_recorded', true);
        $response->assertJsonPath('data.items.0.enrolled_at', '2026-08-01');

        // One day later the term_online student is over 31 days too.
        $this->travelTo(Carbon::parse('2026-09-11 09:00'));
        $this->getJson('/api/v1/term-study-alerts')->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_uses_the_school_setting_and_skips_students_not_studying(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW]);
        $this->admin->tenant->update(['settings' => ['term_study_alert_days' => 10]]);
        $this->setUpAcademicCatalog();
        $studying = Student::factory()->create();
        $completed = Student::factory()->create();

        $this->enroll($studying, 'term', '2026-09-01');
        $completedId = $this->enroll($completed, 'term', '2026-09-01');
        \App\Models\Enrollment::query()->whereKey($completedId)->update(['status' => 'completed']);

        $this->travelTo(Carbon::parse('2026-09-15'));
        $response = $this->getJson('/api/v1/term-study-alerts')->assertOk();
        $response->assertJsonPath('data.days', 10);
        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.student.id', $studying->id);
    }

    public function test_lists_students_with_no_recorded_payment_type_but_not_monthly_ones(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW]);
        $this->setUpAcademicCatalog();
        $monthly = Student::factory()->create();
        $noInvoice = Student::factory()->create();
        $legacyInvoice = Student::factory()->create();

        $this->enroll($monthly, 'monthly', '2026-08-01');
        // Brought over from the old system: no invoice at all.
        \App\Models\Enrollment::factory()->create([
            'student_id' => $noInvoice->id, 'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id, 'enrolled_at' => '2026-08-02', 'status' => 'active',
        ]);
        // An imported invoice with no payment type.
        $legacyId = $this->enroll($legacyInvoice, 'term', '2026-08-03');
        \App\Models\Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_id', $legacyId))->update(['payment_type' => null]);

        $this->travelTo(Carbon::parse('2026-09-15'));
        $response = $this->getJson('/api/v1/term-study-alerts')->assertOk();
        $response->assertJsonCount(2, 'data.items');
        $response->assertJsonPath('data.items.0.student.id', $noInvoice->id);
        $response->assertJsonPath('data.items.0.type_recorded', false);
        $response->assertJsonPath('data.items.1.student.id', $legacyInvoice->id);
        $response->assertJsonPath('data.items.1.type_recorded', false);
    }

    public function test_a_moved_enrollment_takes_the_payment_type_of_the_dropped_one_before_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW]);
        $this->setUpAcademicCatalog();
        $wasTerm = Student::factory()->create();
        $wasMonthly = Student::factory()->create();

        // The old way of changing class: the first enrollment (with the
        // invoice) dropped, a new one opened with no invoice.
        foreach ([[$wasTerm, 'term'], [$wasMonthly, 'monthly']] as [$student, $type]) {
            $oldId = $this->enroll($student, $type, '2026-08-01');
            Enrollment::query()->whereKey($oldId)->update(['status' => Enrollment::STATUS_DROPPED]);
            Enrollment::factory()->create([
                'student_id' => $student->id, 'class_id' => $this->computerEveningClass->id,
                'course_package_id' => $this->msWordPackage->id, 'enrolled_at' => '2026-08-05', 'status' => 'active',
            ]);
        }
        // Promoted to a new grade: no course, no invoice — not a term student.
        Enrollment::factory()->create([
            'student_id' => Student::factory()->create()->id, 'class_id' => $this->computerEveningClass->id,
            'course_package_id' => null, 'enrolled_at' => '2026-08-05', 'status' => 'active',
        ]);

        $this->travelTo(Carbon::parse('2026-09-15'));
        $response = $this->getJson('/api/v1/term-study-alerts')->assertOk();
        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.student.id', $wasTerm->id);
        $response->assertJsonPath('data.items.0.type_recorded', true);
    }

    public function test_an_enrollment_invoice_cannot_be_saved_without_a_payment_type(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $enrollment = Enrollment::factory()->create([
            'student_id' => Student::factory()->create()->id, 'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ]);

        $this->expectException(ValidationException::class);
        app(InvoiceService::class)->create([
            'student_id' => $enrollment->student_id,
            'items' => [[
                'product_id' => $this->msWordPackage->product_id, 'unit_price' => 20,
                'reference_type' => Enrollment::class, 'reference_id' => $enrollment->id,
            ]],
        ], $this->admin);
    }

    public function test_a_paid_invoice_left_by_a_deleted_enrollment_still_gives_the_payment_type(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        // Paid, then the enrollment was deleted (possible before the guard);
        // the student studies on in a newer enrollment with no invoice.
        $goneId = $this->enroll($student, 'term', '2026-08-01');
        Enrollment::query()->whereKey($goneId)->delete();
        Enrollment::factory()->create([
            'student_id' => $student->id, 'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id, 'enrolled_at' => '2026-08-05', 'status' => 'active',
        ]);

        $this->travelTo(Carbon::parse('2026-09-15'));
        $response = $this->getJson('/api/v1/term-study-alerts')->assertOk();
        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.type_recorded', true);
    }

    public function test_an_enrollment_with_an_invoice_cannot_be_deleted_but_one_without_can(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_DELETE]);
        $this->setUpAcademicCatalog();

        $invoicedId = $this->enroll(Student::factory()->create(), 'term', '2026-08-01');
        $this->deleteJson("/api/v1/enrollments/{$invoicedId}")->assertUnprocessable();
        $this->assertNotNull(Enrollment::query()->find($invoicedId));

        $mistake = Enrollment::factory()->create([
            'student_id' => Student::factory()->create()->id, 'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ]);
        $this->deleteJson("/api/v1/enrollments/{$mistake->id}")->assertNoContent();
    }

    public function test_needs_invoice_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/term-study-alerts')->assertForbidden();
    }
}

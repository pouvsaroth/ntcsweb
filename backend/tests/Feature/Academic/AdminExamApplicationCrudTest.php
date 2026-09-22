<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Book;
use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use App\Support\Billing\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * The admin-driven "Exam Application" tab — create/edit an application
 * directly (not just approve/reject a student's own submission), look one up
 * by its enrollment's Enrollment Code, and the Print / Receive Word / Pay
 * Back Exam toolbar actions. See ExamApplicationService and
 * Permissions::EXAM_APPLICATIONS_CREATE/UPDATE/DELETE.
 */
class AdminExamApplicationCrudTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function enrollmentWithCode(): Enrollment
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->create();

        return Enrollment::factory()->forClass($class)->forStudent($student)->create([
            'enrollments_code' => 'NTS-000123-01',
        ]);
    }

    public function test_looking_up_an_enrollment_code_with_no_existing_application_returns_the_enrollment_and_a_null_application(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW]);
        $enrollment = $this->enrollmentWithCode();

        $response = $this->getJson('/api/v1/exam-applications/lookup?enrollment_code=NTS-000123-01');

        $response->assertOk();
        $response->assertJsonPath('data.enrollment_id', $enrollment->id);
        $response->assertJsonPath('data.enrollment_code', 'NTS-000123-01');
        $response->assertJsonPath('data.student.name', $enrollment->student->fullName());
        $response->assertJsonPath('data.exam_application', null);
    }

    public function test_looking_up_an_unknown_enrollment_code_fails(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW]);

        $this->getJson('/api/v1/exam-applications/lookup?enrollment_code=NTS-999999-99')->assertUnprocessable();
    }

    public function test_looking_up_an_enrollment_code_with_an_existing_application_returns_the_most_recent_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW]);
        $enrollment = $this->enrollmentWithCode();
        ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create(['status' => ExamApplication::STATUS_APPROVED]);
        $latest = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create(['status' => ExamApplication::STATUS_PENDING]);

        $response = $this->getJson('/api/v1/exam-applications/lookup?enrollment_code=NTS-000123-01');

        $response->assertOk();
        $response->assertJsonPath('data.exam_application.id', $latest->id);
    }

    public function test_an_admin_can_create_an_exam_application_with_only_the_required_fields(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_CREATE]);
        $enrollment = $this->enrollmentWithCode();

        $response = $this->postJson('/api/v1/exam-applications', [
            'enrollment_id' => $enrollment->id,
            'status' => ExamApplication::STATUS_PENDING,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', ExamApplication::STATUS_PENDING);
        $response->assertJsonPath('data.student.id', $enrollment->student_id);
        $this->assertSame(1, ExamApplication::where('enrollment_id', $enrollment->id)->count());
    }

    public function test_creating_an_exam_application_without_a_status_defaults_to_pending(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_CREATE]);
        $enrollment = $this->enrollmentWithCode();

        $response = $this->postJson('/api/v1/exam-applications', ['enrollment_id' => $enrollment->id]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', ExamApplication::STATUS_PENDING);
    }

    public function test_updating_an_exam_application_without_a_status_leaves_it_unchanged(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)
            ->create(['status' => ExamApplication::STATUS_APPROVED]);

        $response = $this->putJson("/api/v1/exam-applications/{$application->id}", ['remark' => 'Updated']);

        $response->assertOk();
        $response->assertJsonPath('data.status', ExamApplication::STATUS_APPROVED);
    }

    public function test_creating_an_exam_application_requires_the_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $enrollment = $this->enrollmentWithCode();

        $this->postJson('/api/v1/exam-applications', [
            'enrollment_id' => $enrollment->id,
            'status' => ExamApplication::STATUS_PENDING,
        ])->assertForbidden();
    }

    public function test_an_admin_can_update_an_applications_exam_logistics_and_status(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create();
        $book = Book::factory()->create();
        $classroom = Classroom::factory()->create();
        $table = ClassroomTable::factory()->create(['classroom_id' => $classroom->id]);

        $response = $this->putJson("/api/v1/exam-applications/{$application->id}", [
            'exam_date' => now()->addWeek()->toDateString(),
            'exam_time' => '09:00',
            'exam_time_out' => '11:00',
            'book_id' => $book->id,
            'classroom_id' => $classroom->id,
            'table_id' => $table->id,
            'status' => ExamApplication::STATUS_APPROVED,
            'remark' => 'Bring ID card',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.book.id', $book->id);
        $response->assertJsonPath('data.classroom.id', $classroom->id);
        $response->assertJsonPath('data.table.id', $table->id);
        $response->assertJsonPath('data.exam_time_out', '11:00:00');
        $response->assertJsonPath('data.status', ExamApplication::STATUS_APPROVED);
        $response->assertJsonPath('data.remark', 'Bring ID card');
    }

    public function test_a_table_from_a_different_classroom_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create();
        $classroom = Classroom::factory()->create();
        $otherRoomTable = ClassroomTable::factory()->create();

        $response = $this->putJson("/api/v1/exam-applications/{$application->id}", [
            'classroom_id' => $classroom->id,
            'table_id' => $otherRoomTable->id,
            'status' => ExamApplication::STATUS_PENDING,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('table_id');
    }

    public function test_updating_an_exam_application_requires_the_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create();

        $this->putJson("/api/v1/exam-applications/{$application->id}", ['status' => ExamApplication::STATUS_APPROVED])
            ->assertForbidden();
    }

    public function test_print_records_a_real_invoice_and_payment_and_stamps_sold_at(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create();

        $response = $this->postJson("/api/v1/exam-applications/{$application->id}/print", [
            'fee' => 15.50,
            'currency' => 'USD',
            'payment_method' => PaymentMethod::CASH,
            'print_date' => '2026-09-20',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.fee_amount', '15.50');
        $response->assertJsonPath('data.fee_currency', 'USD');

        $fresh = $application->fresh();
        $this->assertNotNull($fresh->sold_at);

        $invoice = Invoice::where('student_id', $enrollment->student_id)->latest('id')->firstOrFail();
        $this->assertSame('15.50', (string) $invoice->total);
        $this->assertSame('USD', $invoice->currency);

        $item = $invoice->items()->firstOrFail();
        $this->assertSame(ExamApplication::class, $item->reference_type);
        $this->assertSame($application->id, $item->reference_id);

        $payment = Payment::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('15.50', (string) $payment->amount);
        $this->assertSame(PaymentMethod::CASH, $payment->payment_method);
        $this->assertSame('2026-09-20', $payment->payment_date->toDateString());
        $this->assertSame($admin->id, $payment->received_by);

        // Auto-provisioned once, reused on a second Print rather than duplicated.
        $this->assertSame(1, Product::where('code', 'EXAM-FEE')->count());
    }

    public function test_print_requires_a_fee_payment_method_and_print_date(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create();

        $this->postJson("/api/v1/exam-applications/{$application->id}/print", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fee', 'payment_method', 'print_date']);
    }

    public function test_printing_requires_the_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create();

        $this->postJson("/api/v1/exam-applications/{$application->id}/print", [
            'fee' => 10, 'payment_method' => PaymentMethod::CASH, 'print_date' => '2026-09-20',
        ])->assertForbidden();
        $this->assertNull($application->fresh()->sold_at);
    }

    public function test_receive_word_stamps_received_at(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create();

        $this->postJson('/api/v1/exam-applications/receive', ['ids' => [$application->id]])->assertOk();

        $this->assertNotNull($application->fresh()->received_at);
    }

    public function test_pay_back_exam_stamps_paid_back_at(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create();

        $this->postJson('/api/v1/exam-applications/pay-back', ['ids' => [$application->id]])->assertOk();

        $this->assertNotNull($application->fresh()->paid_back_at);
    }

    public function test_send_to_exam_creates_a_draft_application(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_CREATE]);
        $enrollment = $this->enrollmentWithCode();

        // Same shape SendToExamModal.vue's bulk roster action sends.
        $response = $this->postJson('/api/v1/exam-applications', [
            'enrollment_id' => $enrollment->id,
            'status' => ExamApplication::STATUS_DRAFT,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', ExamApplication::STATUS_DRAFT);
    }

    public function test_marking_not_exam_completes_the_enrollment(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $enrollment = $this->enrollmentWithCode();
        $enrollment->update(['status' => Enrollment::STATUS_ACTIVE]);
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create([
            'status' => ExamApplication::STATUS_DRAFT,
        ]);

        $response = $this->postJson("/api/v1/exam-applications/{$application->id}/not-exam");

        $response->assertOk();
        $response->assertJsonPath('data.status', ExamApplication::STATUS_NOT_EXAM);
        $this->assertSame(Enrollment::STATUS_COMPLETED, $enrollment->fresh()->status);
    }

    public function test_marking_not_exam_on_a_non_draft_application_fails(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create([
            'status' => ExamApplication::STATUS_PENDING,
        ]);

        $this->postJson("/api/v1/exam-applications/{$application->id}/not-exam")->assertUnprocessable();
        $this->assertSame(ExamApplication::STATUS_PENDING, $application->fresh()->status);
    }

    public function test_marking_not_exam_requires_the_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create([
            'status' => ExamApplication::STATUS_DRAFT,
        ]);

        $this->postJson("/api/v1/exam-applications/{$application->id}/not-exam")->assertForbidden();
    }

    public function test_bulk_actions_require_the_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create();

        $this->postJson('/api/v1/exam-applications/receive', ['ids' => [$application->id]])->assertForbidden();
        $this->assertNull($application->fresh()->received_at);
    }

    public function test_an_admin_can_delete_an_exam_application(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_DELETE, Permissions::EXAM_APPLICATIONS_VIEW]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create();

        $this->deleteJson("/api/v1/exam-applications/{$application->id}")->assertOk();

        $this->assertSoftDeleted($application);
        $this->getJson('/api/v1/exam-applications')->assertJsonCount(0, 'data');
    }

    public function test_student_submitted_filter_only_returns_applications_the_student_filed_themselves(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW]);
        $enrollment = $this->enrollmentWithCode();
        $adminCreated = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)
            ->create(['student_marked_paid_at' => null]);
        $studentSubmitted = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)
            ->create(['student_marked_paid_at' => now()]);

        $response = $this->getJson('/api/v1/exam-applications?student_submitted=1');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $studentSubmitted->id);
        $this->assertNotEquals($adminCreated->id, $response->json('data.0.id'));
    }

    public function test_awaiting_action_filter_returns_draft_pending_and_unscored_approved_only(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW]);
        $enrollment = $this->enrollmentWithCode();

        $draft = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)
            ->create(['status' => ExamApplication::STATUS_DRAFT]);
        $pending = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)
            ->create(['status' => ExamApplication::STATUS_PENDING]);
        $approvedUnscored = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)
            ->create(['status' => ExamApplication::STATUS_APPROVED]);
        $approvedScored = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)
            ->create(['status' => ExamApplication::STATUS_APPROVED]);
        $approvedScored->score()->create(['score' => '85.00']);
        $makeUpUnscored = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)
            ->create(['status' => ExamApplication::STATUS_MAKE_UP]);
        $makeUpScored = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)
            ->create(['status' => ExamApplication::STATUS_MAKE_UP]);
        $makeUpScored->score()->create(['score' => '90.00']);
        ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)
            ->create(['status' => ExamApplication::STATUS_REJECTED]);
        ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)
            ->create(['status' => ExamApplication::STATUS_NOT_EXAM]);

        $response = $this->getJson('/api/v1/exam-applications?awaiting_action=1');

        $response->assertOk();
        $response->assertJsonCount(4, 'data');
        $ids = collect($response->json('data'))->pluck('id');
        $ids->each(fn ($id) => $this->assertNotEquals($approvedScored->id, $id));
        $ids->each(fn ($id) => $this->assertNotEquals($makeUpScored->id, $id));
        $this->assertTrue($ids->contains($draft->id));
        $this->assertTrue($ids->contains($pending->id));
        $this->assertTrue($ids->contains($approvedUnscored->id));
        $this->assertTrue($ids->contains($makeUpUnscored->id));
    }

    public function test_deleting_an_exam_application_requires_the_delete_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $enrollment = $this->enrollmentWithCode();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create();

        $this->deleteJson("/api/v1/exam-applications/{$application->id}")->assertForbidden();
    }
}

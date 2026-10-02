<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\ExamScore;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Examination → Certificate — passed students (score ≥ 85) and their
 * certificate photo. See ExamApplicationController::index()'s `certificate`
 * filter and photoReceived().
 */
class ExamCertificateTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function scoredApplication(float $score): ExamApplication
    {
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->forClass(SchoolClass::factory()->create())->forStudent($student)->create();
        $application = ExamApplication::factory()->forStudent($student)->forEnrollment($enrollment)->create(['status' => ExamApplication::STATUS_APPROVED]);
        ExamScore::query()->create(['exam_application_id' => $application->id, 'score' => $score]);

        return $application;
    }

    public function test_the_certificate_list_shows_only_students_who_scored_85_or_more(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW]);
        $excellent = $this->scoredApplication(96);
        $borderline = $this->scoredApplication(85);
        $this->scoredApplication(84.99);
        ExamApplication::factory()->create(['status' => ExamApplication::STATUS_APPROVED]); // not scored yet

        $response = $this->getJson('/api/v1/exam-applications?certificate=1')->assertOk();

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$excellent->id, $borderline->id], $rows->keys()->all());
        $this->assertSame(['score' => '96.00', 'mention' => 'excellent'], $rows[$excellent->id]['score']);
        $this->assertSame('good', $rows[$borderline->id]['score']['mention']);
    }

    public function test_photo_received_can_be_recorded_for_selected_students_and_filtered(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW, Permissions::EXAM_APPLICATIONS_UPDATE]);
        $first = $this->scoredApplication(90);
        $second = $this->scoredApplication(88);
        $waiting = $this->scoredApplication(87);

        $this->postJson('/api/v1/exam-applications/photo-received', [
            'ids' => [$first->id, $second->id],
            'received_date' => now()->toDateString(),
            'remark' => '4x6 photo, 2 copies',
        ])->assertOk();

        $this->assertSame(now()->toDateString(), $first->fresh()->photo_received_date->toDateString());
        $this->assertSame('4x6 photo, 2 copies', $second->fresh()->photo_received_remark);
        $this->assertSame($admin->id, (int) $second->fresh()->photo_received_by);
        $this->assertNull($waiting->fresh()->photo_received_date);

        $received = collect($this->getJson('/api/v1/exam-applications?certificate=1&photo_received=yes')->json('data'))->pluck('id');
        $notYet = collect($this->getJson('/api/v1/exam-applications?certificate=1&photo_received=no')->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $received->all());
        $this->assertSame([$waiting->id], $notYet->all());
    }

    public function test_a_student_who_did_not_pass_cannot_be_marked(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $passed = $this->scoredApplication(90);
        $failed = $this->scoredApplication(60);

        $this->postJson('/api/v1/exam-applications/photo-received', [
            'ids' => [$passed->id, $failed->id],
            'received_date' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['ids']);

        $this->assertNull($passed->fresh()->photo_received_date);
    }

    public function test_marking_needs_a_date_not_in_the_future_and_the_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $application = $this->scoredApplication(90);

        $this->postJson('/api/v1/exam-applications/photo-received', ['ids' => [$application->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['received_date']);
        $this->postJson('/api/v1/exam-applications/photo-received', ['ids' => [$application->id], 'received_date' => now()->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['received_date']);

        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW]);
        $this->postJson('/api/v1/exam-applications/photo-received', ['ids' => [$application->id], 'received_date' => now()->toDateString()])
            ->assertForbidden();
    }
}

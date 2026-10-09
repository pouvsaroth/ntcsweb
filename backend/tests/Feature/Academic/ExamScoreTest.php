<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\ExamScore;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * The Grades tab — see ExamScoreService. Scores only go on approved exam
 * applications, and without exam-scores.manage-all only on applications in
 * classes the user's own Staff record teaches.
 */
class ExamScoreTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function application(array $overrides = [], ?SchoolClass $class = null, ?CoursePackage $package = null, ?Book $book = null): ExamApplication
    {
        $class ??= SchoolClass::factory()->create();
        $package ??= CoursePackage::factory()->create();
        $enrollment = Enrollment::factory()->forClass($class)->create(['course_package_id' => $package->id]);

        return ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create([
            'status' => ExamApplication::STATUS_APPROVED,
            'book_id' => ($book ?? Book::factory()->create())->id,
            ...$overrides,
        ]);
    }

    public function test_the_list_only_contains_approved_applications_filtered_by_course_class_and_book(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_MANAGE_ALL]);
        $class = SchoolClass::factory()->create();
        $package = CoursePackage::factory()->create();
        $book = Book::factory()->create();

        $match = $this->application([], $class, $package, $book);
        $this->application(['status' => ExamApplication::STATUS_PENDING], $class, $package, $book);
        $this->application(['status' => ExamApplication::STATUS_REJECTED], $class, $package, $book);
        $this->application([], $class, $package); // other book
        $this->application([], null, $package, $book); // other class

        $response = $this->getJson("/api/v1/exam-scores?course_package_id={$package->id}&class_id={$class->id}&book_id={$book->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.exam_application_id', $match->id);
        $response->assertJsonPath('data.0.score', null);
    }

    public function test_the_list_is_ordered_by_exam_date_ascending_and_carries_each_scores_mention(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_MANAGE_ALL]);
        $later = $this->application(['exam_date' => '2026-03-10']);
        $earlier = $this->application(['exam_date' => '2026-01-05']);
        $unscored = $this->application(['exam_date' => '2026-02-01']);
        ExamScore::query()->create(['exam_application_id' => $later->id, 'score' => 96]);
        ExamScore::query()->create(['exam_application_id' => $earlier->id, 'score' => 80]);

        $this->getJson('/api/v1/exam-scores')
            ->assertOk()
            ->assertJsonPath('data.0.exam_application_id', $earlier->id)
            ->assertJsonPath('data.0.mention', 'fail')
            ->assertJsonPath('data.1.exam_application_id', $unscored->id)
            ->assertJsonPath('data.1.mention', null)
            ->assertJsonPath('data.2.exam_application_id', $later->id)
            ->assertJsonPath('data.2.mention', 'excellent');
    }

    public function test_options_list_the_course_class_book_combinations_that_have_approved_applications(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_VIEW, Permissions::EXAM_SCORES_MANAGE_ALL]);
        $app = $this->application();
        $this->application(['status' => ExamApplication::STATUS_PENDING]);

        $response = $this->getJson('/api/v1/exam-scores/options');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.book.id', $app->book_id);
        $response->assertJsonPath('data.0.school_class.id', $app->enrollment->class_id);
    }

    public function test_scores_can_be_saved_updated_and_cleared_in_one_batch(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_MANAGE_ALL]);
        $first = $this->application();
        $second = $this->application();
        ExamScore::query()->create(['exam_application_id' => $second->id, 'score' => 40]);

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $first->id, 'score' => 87.5, 'remark' => 'Good'],
            ['exam_application_id' => $second->id, 'score' => null],
        ]])->assertOk();

        $this->assertSame('87.50', ExamScore::query()->where('exam_application_id', $first->id)->value('score'));
        $this->assertFalse(ExamScore::query()->where('exam_application_id', $second->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::EXAM_SCORES_RECORDED)->exists());

        $this->getJson("/api/v1/exam-scores?student_id={$first->student_id}")
            ->assertJsonPath('data.0.score', '87.50')
            ->assertJsonPath('data.0.remark', 'Good');
    }

    public function test_a_new_score_gets_the_current_academic_year_and_keeps_it_when_edited(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_MANAGE_ALL]);
        $last = AcademicYear::factory()->create(['name' => '2025-2026', 'is_current' => true]);
        $app = $this->application();

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $app->id, 'score' => 70],
        ]])->assertOk();

        $this->assertSame($last->id, ExamScore::query()->where('exam_application_id', $app->id)->value('academic_year_id'));

        // A new year becomes current; correcting the old score later must
        // not move it into the new year.
        $last->update(['is_current' => false]);
        AcademicYear::factory()->create(['name' => '2026-2027', 'is_current' => true]);

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $app->id, 'score' => 75],
        ]])->assertOk();

        $score = ExamScore::query()->where('exam_application_id', $app->id)->sole();
        $this->assertSame('75.00', $score->score);
        $this->assertSame($last->id, $score->academic_year_id);
    }

    public function test_an_older_score_without_a_year_gets_the_current_one_when_edited(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_MANAGE_ALL]);
        $current = AcademicYear::factory()->create(['is_current' => true]);
        $app = $this->application();
        ExamScore::query()->create(['exam_application_id' => $app->id, 'score' => 60]);

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $app->id, 'score' => 65],
        ]])->assertOk();

        $this->assertSame($current->id, ExamScore::query()->where('exam_application_id', $app->id)->value('academic_year_id'));
    }

    public function test_saving_a_score_without_make_up_completes_the_enrollment(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_MANAGE_ALL]);
        $app = $this->application();
        $app->enrollment->update(['status' => Enrollment::STATUS_ACTIVE]);

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $app->id, 'score' => 87.5],
        ]])->assertOk();

        $this->assertSame(Enrollment::STATUS_COMPLETED, $app->enrollment->fresh()->status);
    }

    public function test_checking_make_up_creates_a_scoreable_retake_application(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_MANAGE_ALL]);
        $app = $this->application();

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $app->id, 'score' => 40, 'make_up' => true],
        ]])->assertOk();

        $retake = ExamApplication::query()->where('retake_of_id', $app->id)->first();
        $this->assertNotNull($retake);
        $this->assertSame(ExamApplication::STATUS_MAKE_UP, $retake->status);
        $this->assertSame($app->student_id, $retake->student_id);
        $this->assertSame($app->enrollment_id, $retake->enrollment_id);
        $this->assertSame($app->book_id, $retake->book_id);

        // The retake itself is immediately scoreable — no separate approval step.
        $this->getJson('/api/v1/exam-scores')->assertJsonFragment(['exam_application_id' => $retake->id]);

        $this->getJson("/api/v1/exam-scores?student_id={$app->student_id}")
            ->assertJsonPath('data.0.has_make_up', true);

        // Unlike the no-retake case, there's still an exam left to sit —
        // the enrollment must not be marked completed underneath it.
        $this->assertNotSame(Enrollment::STATUS_COMPLETED, $app->enrollment->fresh()->status);
    }

    public function test_checking_make_up_twice_never_creates_a_second_retake(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_MANAGE_ALL]);
        $app = $this->application();

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $app->id, 'score' => 40, 'make_up' => true],
        ]])->assertOk();
        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $app->id, 'score' => 42, 'make_up' => true],
        ]])->assertOk();

        $this->assertSame(1, ExamApplication::query()->where('retake_of_id', $app->id)->count());
    }

    public function test_a_score_cannot_be_saved_for_an_application_that_is_not_approved(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_MANAGE_ALL]);
        $pending = $this->application(['status' => ExamApplication::STATUS_PENDING]);

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $pending->id, 'score' => 90],
        ]])->assertUnprocessable();

        $this->assertSame(0, ExamScore::query()->count());
    }

    public function test_a_score_must_be_between_0_and_100(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_MANAGE_ALL]);
        $app = $this->application();

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $app->id, 'score' => 101],
        ]])->assertUnprocessable()->assertJsonValidationErrors('entries.0.score');
    }

    public function test_a_teacher_only_sees_and_scores_applications_in_classes_they_teach(): void
    {
        $user = $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_VIEW, Permissions::EXAM_SCORES_UPDATE]);
        $teacher = Staff::factory()->withUser($user)->create();
        $own = $this->application([], SchoolClass::factory()->withTeacher($teacher)->create());
        $other = $this->application();

        $this->getJson('/api/v1/exam-scores')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.exam_application_id', $own->id);

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $own->id, 'score' => 70],
        ]])->assertOk();

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $other->id, 'score' => 70],
        ]])->assertUnprocessable();

        $this->assertSame(1, ExamScore::query()->count());
    }

    public function test_a_user_without_a_staff_record_or_manage_all_sees_nothing(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_VIEW]);
        $this->application();

        $this->getJson('/api/v1/exam-scores')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_saving_requires_an_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCORES_VIEW]);
        $app = $this->application();

        $this->postJson('/api/v1/exam-scores', ['entries' => [
            ['exam_application_id' => $app->id, 'score' => 50],
        ]])->assertForbidden();
    }

    public function test_viewing_requires_a_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_VIEW]);

        $this->getJson('/api/v1/exam-scores')->assertForbidden();
    }
}

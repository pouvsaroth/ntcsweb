<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\Book;
use App\Models\CurriculumLesson;
use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\ExamScore;
use App\Models\GradeLevel;
use App\Models\Holiday;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentPromotion;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * School setup (docs/academics/school-setup.md): terms, grade levels and
 * classes, the curriculum, the school calendar, promotion rules and
 * promoting students.
 */
class SchoolSetupTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    // --- Stage 1: terms, grade levels, classes ------------------------------

    public function test_terms_belong_to_a_year_and_their_names_are_unique_within_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_TERMS_VIEW, Permissions::ACADEMIC_TERMS_MANAGE]);
        $year = AcademicYear::factory()->create(['name' => '2026-2027']);
        $other = AcademicYear::factory()->create(['name' => '2027-2028']);

        $this->postJson('/api/v1/academic-terms', ['academic_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-11-01', 'end_date' => '2027-03-31'])
            ->assertCreated()->assertJsonPath('data.academic_year', '2026-2027');
        $this->postJson('/api/v1/academic-terms', ['academic_year_id' => $year->id, 'name' => 'Semester 1'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/academic-terms', ['academic_year_id' => $other->id, 'name' => 'Semester 1'])->assertCreated();
        $this->postJson('/api/v1/academic-terms', ['academic_year_id' => $year->id, 'name' => 'Bad', 'start_date' => '2027-01-01', 'end_date' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');

        $this->getJson("/api/v1/academic-terms?academic_year_id={$year->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_managing_terms_needs_the_manage_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_TERMS_VIEW]);
        $year = AcademicYear::factory()->create();

        $this->getJson('/api/v1/academic-terms')->assertOk();
        $this->postJson('/api/v1/academic-terms', ['academic_year_id' => $year->id, 'name' => 'Term 1'])->assertForbidden();
    }

    public function test_grade_1_to_12_in_one_click_and_a_used_grade_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::GRADE_LEVELS_VIEW, Permissions::GRADE_LEVELS_MANAGE]);

        $this->postJson('/api/v1/grade-levels/defaults')->assertOk()->assertJsonPath('data.added', 12);
        $this->postJson('/api/v1/grade-levels/defaults')->assertOk()->assertJsonPath('data.added', 0);

        $levels = $this->getJson('/api/v1/grade-levels')->assertOk()->json('data');
        $this->assertCount(12, $levels);
        $this->assertSame(['G1', 'primary'], [$levels[0]['code'], $levels[0]['stage']]);
        $this->assertSame(['G12', 'upper_secondary'], [$levels[11]['code'], $levels[11]['stage']]);

        $grade7 = GradeLevel::query()->where('code', 'G7')->firstOrFail();
        SchoolClass::factory()->create(['grade_level_id' => $grade7->id]);
        $this->deleteJson("/api/v1/grade-levels/{$grade7->id}")->assertUnprocessable();

        $this->postJson('/api/v1/grade-levels', ['code' => 'g1', 'name' => 'Again', 'level_order' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_class_has_a_grade_level_and_an_academic_year(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_VIEW, Permissions::CLASSES_CREATE]);
        $grade = GradeLevel::query()->create(['code' => 'G7', 'name' => 'Grade 7', 'level_order' => 7]);
        $year = AcademicYear::factory()->create(['name' => '2026-2027']);

        $id = $this->postJson('/api/v1/classes', ['name' => '7A', 'grade_level_id' => $grade->id, 'academic_year_id' => $year->id])
            ->assertCreated()
            ->assertJsonPath('data.grade_level.name', 'Grade 7')
            ->assertJsonPath('data.academic_year.name', '2026-2027')
            ->json('data.id');

        $this->getJson("/api/v1/classes?filter[grade_level_id]={$grade->id}")->assertOk()->assertJsonPath('data.0.id', $id);
    }

    // --- Stage 2: subjects by grade, curriculum ----------------------------

    public function test_the_curriculum_of_a_grade_subjects_hours_and_lessons(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CURRICULUM_VIEW, Permissions::CURRICULUM_MANAGE, Permissions::BOOKS_VIEW]);
        $grade = GradeLevel::query()->create(['code' => 'G7', 'name' => 'Grade 7', 'level_order' => 7]);
        $math = Book::factory()->create(['title' => 'Mathematics']);
        $khmer = Book::factory()->create(['title' => 'Khmer']);

        $this->putJson('/api/v1/curriculum/subjects', ['grade_level_id' => $grade->id, 'book_id' => $math->id, 'hours_per_week' => 6])->assertOk();
        $this->putJson('/api/v1/curriculum/subjects', ['grade_level_id' => $grade->id, 'book_id' => $khmer->id, 'hours_per_week' => 5])->assertOk();
        $this->putJson('/api/v1/curriculum/subjects', ['grade_level_id' => $grade->id, 'book_id' => $math->id, 'hours_per_week' => 7])->assertOk();

        $this->postJson('/api/v1/curriculum/lessons', ['grade_level_id' => $grade->id, 'book_id' => $math->id, 'title' => 'Fractions', 'hours' => 10])->assertCreated();
        $this->postJson('/api/v1/curriculum/lessons', ['grade_level_id' => $grade->id, 'book_id' => $math->id, 'title' => 'Equations', 'hours' => 12])
            ->assertCreated()->assertJsonPath('data.sort_order', 2);

        $other = Book::factory()->create(['title' => 'Physics']);
        $this->postJson('/api/v1/curriculum/lessons', ['grade_level_id' => $grade->id, 'book_id' => $other->id, 'title' => 'Motion'])
            ->assertUnprocessable()->assertJsonValidationErrors('book_id');

        $data = $this->getJson("/api/v1/curriculum?grade_level_id={$grade->id}")->assertOk()->json('data');
        $this->assertSame(['Khmer', 'Mathematics'], array_column($data['subjects'], 'title'));
        $this->assertEquals(12, $data['hours_per_week']);
        $this->assertEquals(22, $data['subjects'][1]['lessons_hours']);
        $this->assertSame(['Physics'], array_column($data['available_subjects'], 'title'));

        // Subjects by grade level on the Books list.
        $this->getJson("/api/v1/books?filter[grade_level_id]={$grade->id}")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.grade_levels.0.code', 'G7');

        $this->deleteJson('/api/v1/curriculum/subjects', ['grade_level_id' => $grade->id, 'book_id' => $math->id])->assertNoContent();
        $this->assertSame(0, CurriculumLesson::query()->count());
    }

    // --- Stage 3: school calendar ------------------------------------------

    public function test_the_calendar_shows_its_days_the_holidays_and_the_terms(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::SCHOOL_CALENDAR_VIEW, Permissions::SCHOOL_CALENDAR_MANAGE]);
        $year = AcademicYear::factory()->create();
        AcademicTerm::query()->create(['academic_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-11-01', 'end_date' => '2027-03-31']);
        Holiday::query()->create(['name' => 'Water Festival', 'start_date' => '2026-11-04', 'end_date' => '2026-11-06']);

        $this->postJson('/api/v1/school-calendar', ['title' => 'School opens', 'type' => 'opening', 'start_date' => '2026-11-02'])
            ->assertCreated()->assertJsonPath('data.end_date', '2026-11-02');
        $this->postJson('/api/v1/school-calendar', ['title' => 'Midterm exams', 'type' => 'exam', 'start_date' => '2026-12-14', 'end_date' => '2026-12-18'])->assertCreated();
        $this->postJson('/api/v1/school-calendar', ['title' => 'Bad', 'type' => 'party', 'start_date' => '2026-12-14'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $november = $this->getJson('/api/v1/school-calendar?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $this->assertSame(['School opens'], array_column($november['events'], 'title'));
        $this->assertSame(['Water Festival'], array_column($november['holidays'], 'title'));
        $this->assertSame(['Semester 1'], array_column($november['terms'], 'name'));
    }

    // --- Stage 4: promotion rules and promoting students --------------------

    public function test_promotion_rules_a_default_and_one_per_grade(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROMOTION_RULES_VIEW, Permissions::PROMOTION_RULES_MANAGE]);
        $grade = GradeLevel::query()->create(['code' => 'G9', 'name' => 'Grade 9', 'level_order' => 9]);

        $this->putJson('/api/v1/promotion-rules', ['min_average' => 50, 'pass_mark' => 50])->assertOk();
        $this->putJson('/api/v1/promotion-rules', ['min_average' => 55, 'min_attendance' => 80, 'pass_mark' => 50])->assertOk();
        $this->putJson('/api/v1/promotion-rules', ['grade_level_id' => $grade->id, 'max_failed_subjects' => 1, 'pass_mark' => 40])->assertOk();
        $this->putJson('/api/v1/promotion-rules', ['min_average' => 120, 'pass_mark' => 50])->assertUnprocessable();

        $rules = $this->getJson('/api/v1/promotion-rules')->assertOk()->json('data');
        $this->assertCount(2, $rules);
        $this->assertNull($rules[0]['grade_level_id']);
        $this->assertEquals(55, $rules[0]['min_average']);
        $this->assertSame('Grade 9', $rules[1]['grade_level']);
    }

    public function test_students_are_checked_against_the_rule_then_promoted_or_kept(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::STUDENT_PROMOTIONS_VIEW, Permissions::STUDENT_PROMOTIONS_RUN, Permissions::PROMOTION_RULES_MANAGE,
        ]);
        $g7 = GradeLevel::query()->create(['code' => 'G7', 'name' => 'Grade 7', 'level_order' => 7]);
        $g8 = GradeLevel::query()->create(['code' => 'G8', 'name' => 'Grade 8', 'level_order' => 8]);
        $year = AcademicYear::factory()->create(['name' => '2026']);
        $class7 = SchoolClass::factory()->create(['name' => '7A', 'grade_level_id' => $g7->id, 'academic_year_id' => $year->id]);
        $class8 = SchoolClass::factory()->create(['name' => '8A', 'grade_level_id' => $g8->id, 'status' => SchoolClass::STATUS_UPCOMING]);
        $repeat7 = SchoolClass::factory()->create(['name' => '7A next year', 'grade_level_id' => $g7->id, 'status' => SchoolClass::STATUS_UPCOMING]);

        $this->putJson('/api/v1/promotion-rules', ['min_average' => 50, 'min_attendance' => 75, 'max_failed_subjects' => 1, 'pass_mark' => 50])->assertOk();

        $math = Book::factory()->create();
        $khmer = Book::factory()->create();
        $good = $this->student('Dara', $class7, [$math->id => 80, $khmer->id => 70], [AttendanceStatus::PRESENT, AttendanceStatus::PRESENT, AttendanceStatus::LATE, AttendanceStatus::EXCUSED]);
        $weak = $this->student('Sok', $class7, [$math->id => 30, $khmer->id => 40], [AttendanceStatus::PRESENT, AttendanceStatus::ABSENT, AttendanceStatus::ABSENT, AttendanceStatus::ABSENT]);
        $new = $this->student('Vanna', $class7, [], []);

        $data = $this->getJson("/api/v1/student-promotions?class_id={$class7->id}")->assertOk()->json('data');
        $this->assertSame('Grade 8', $data['next_grade_level']['name']);
        $this->assertSame(['8A'], array_column($data['promote_classes'], 'name'));
        $this->assertSame(['7A next year'], array_column($data['repeat_classes'], 'name'));
        $rows = collect($data['students'])->keyBy('enrollment_id');
        $this->assertTrue($rows[$good->id]['passes']);
        $this->assertEquals(75, $rows[$good->id]['average']);
        $this->assertEquals(100, $rows[$good->id]['attendance_rate']);
        $this->assertFalse($rows[$weak->id]['passes']);
        $this->assertEqualsCanonicalizing(['average_low', 'attendance_low', 'too_many_failed'], $rows[$weak->id]['reasons']);
        $this->assertEqualsCanonicalizing(['no_scores', 'no_attendance'], $rows[$new->id]['reasons']);

        $this->postJson('/api/v1/student-promotions', [
            'class_id' => $class7->id, 'to_class_id' => $class8->id, 'repeat_class_id' => $repeat7->id,
            'students' => [
                ['enrollment_id' => $good->id, 'action' => 'promote'],
                ['enrollment_id' => $weak->id, 'action' => 'repeat'],
            ],
        ])->assertOk()->assertJsonPath('data.promoted', 1)->assertJsonPath('data.repeated', 1);

        $this->assertSame(Enrollment::STATUS_COMPLETED, $good->fresh()->status);
        $this->assertTrue(Enrollment::query()->where('student_id', $good->student_id)->where('class_id', $class8->id)->where('status', Enrollment::STATUS_ACTIVE)->exists());
        $this->assertTrue(Enrollment::query()->where('student_id', $weak->student_id)->where('class_id', $repeat7->id)->exists());
        $this->assertSame(Enrollment::STATUS_ACTIVE, $new->fresh()->status);

        // Never twice: a second run skips them.
        $this->postJson('/api/v1/student-promotions', [
            'class_id' => $class7->id, 'to_class_id' => $class8->id,
            'students' => [['enrollment_id' => $good->id, 'action' => 'promote']],
        ])->assertOk()->assertJsonPath('data.skipped', 1);
        $this->assertSame(1, StudentPromotion::query()->where('result', StudentPromotion::PROMOTED)->count());

        $after = collect($this->getJson("/api/v1/student-promotions?class_id={$class7->id}")->json('data.students'))->keyBy('enrollment_id');
        $this->assertSame('promoted', $after[$good->id]['done']['result']);
        $this->assertSame('8A', $after[$good->id]['done']['to_class']);
    }

    public function test_promoting_needs_a_class_to_go_into_and_the_run_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENT_PROMOTIONS_VIEW, Permissions::STUDENT_PROMOTIONS_RUN]);
        $class = SchoolClass::factory()->create();
        $enrollment = $this->student('Dara', $class, [], []);

        $this->postJson('/api/v1/student-promotions', ['class_id' => $class->id, 'students' => [['enrollment_id' => $enrollment->id, 'action' => 'promote']]])
            ->assertUnprocessable()->assertJsonValidationErrors('to_class_id');
        $this->postJson('/api/v1/student-promotions', ['class_id' => $class->id, 'students' => [['enrollment_id' => $enrollment->id, 'action' => 'graduate']]])
            ->assertOk()->assertJsonPath('data.graduated', 1);

        $this->actingAsAdminWithPermissions([Permissions::STUDENT_PROMOTIONS_VIEW]);
        $this->postJson('/api/v1/student-promotions', ['class_id' => $class->id, 'students' => [['enrollment_id' => $enrollment->id, 'action' => 'graduate']]])
            ->assertForbidden();
    }

    /**
     * @param  array<int, float>  $scores  book id => score
     * @param  list<string>  $days  attendance statuses, one day each
     */
    private function student(string $name, SchoolClass $class, array $scores, array $days): Enrollment
    {
        $student = Student::factory()->create(['first_name' => $name]);
        $enrollment = Enrollment::factory()->forClass($class)->forStudent($student)->create();
        foreach ($scores as $bookId => $score) {
            $application = ExamApplication::factory()->forStudent($student)->forEnrollment($enrollment)
                ->create(['status' => ExamApplication::STATUS_APPROVED, 'book_id' => $bookId]);
            ExamScore::query()->create(['exam_application_id' => $application->id, 'score' => $score]);
        }
        foreach ($days as $i => $status) {
            AttendanceRecord::factory()->forEnrollment($enrollment)->onDate(now()->subDays($i + 1)->toDateString())->status($status)->create();
        }

        return $enrollment;
    }
}

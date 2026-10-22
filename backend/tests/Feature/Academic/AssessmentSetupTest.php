<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\AssessmentScheme;
use App\Models\Book;
use App\Models\Classroom;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Examination, gradebook & assessment — step 7a (docs/academics/gradebook.md):
 * assessment setup per grade level and the exam schedule.
 */
class AssessmentSetupTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function grade(string $code = 'G7', int $order = 7): GradeLevel
    {
        return GradeLevel::query()->create(['code' => $code, 'name' => "Grade {$order}", 'stage' => 'lower_secondary', 'level_order' => $order]);
    }

    private function scheme(array $overrides = []): array
    {
        return $overrides + [
            'grade_level_id' => null, 'mode' => 'score', 'name' => 'Secondary', 'max_score' => 50,
            'components' => [
                ['name' => 'Monthly test', 'name_km' => 'ប្រឡងប្រចាំខែ', 'kind' => 'monthly', 'max_score' => 50, 'weight' => 50, 'occurrences' => 3],
                ['name' => 'Semester exam', 'kind' => 'exam', 'max_score' => 50, 'weight' => 50, 'occurrences' => 1],
            ],
            'bands' => [['letter' => 'A', 'min_percent' => 90], ['letter' => 'F', 'min_percent' => 0]],
        ];
    }

    // --- Assessment setup ------------------------------------------------------

    public function test_a_scheme_is_saved_whole_and_its_children_keep_their_ids(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSESSMENT_VIEW, Permissions::ASSESSMENT_MANAGE]);

        $created = $this->postJson('/api/v1/assessment-schemes', $this->scheme())->assertCreated()
            ->assertJsonPath('data.weights_total', 100)
            ->assertJsonPath('data.warnings', [])
            ->assertJsonCount(2, 'data.components')
            ->assertJsonPath('data.components.0.occurrences', 3)
            ->json('data');

        // Change the first, drop the second, add a third — the first keeps its id.
        $first = $created['components'][0];
        $this->putJson("/api/v1/assessment-schemes/{$created['id']}", $this->scheme([
            'components' => [
                ['id' => $first['id'], 'name' => 'Monthly test', 'kind' => 'monthly', 'max_score' => 50, 'weight' => 40, 'occurrences' => 3],
                ['name' => 'Project', 'kind' => 'project', 'max_score' => 20, 'weight' => 30, 'occurrences' => 1],
            ],
        ]))->assertOk()
            ->assertJsonPath('data.components.0.id', $first['id'])
            ->assertJsonPath('data.components.0.weight', 40)
            ->assertJsonPath('data.components.1.name', 'Project')
            ->assertJsonPath('data.weights_total', 70)
            ->assertJsonPath('data.warnings', ['weights_not_100']);

        $this->assertSame(2, AssessmentScheme::query()->findOrFail($created['id'])->components()->count());
        // Bands left out of the request stay as they were.
        $this->getJson('/api/v1/assessment-schemes')->assertOk()->assertJsonCount(2, 'data.0.bands');
    }

    public function test_one_default_and_one_scheme_per_grade(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSESSMENT_VIEW, Permissions::ASSESSMENT_MANAGE]);
        $g7 = $this->grade();

        $this->postJson('/api/v1/assessment-schemes', $this->scheme())->assertCreated();
        $this->postJson('/api/v1/assessment-schemes', $this->scheme())->assertUnprocessable()->assertJsonValidationErrors('grade_level_id');

        $this->postJson('/api/v1/assessment-schemes', $this->scheme(['grade_level_id' => $g7->id]))->assertCreated();
        $this->postJson('/api/v1/assessment-schemes', $this->scheme(['grade_level_id' => $g7->id]))->assertUnprocessable()->assertJsonValidationErrors('grade_level_id');

        // The grade's own first, else the default.
        $g8 = $this->grade('G8', 8);
        $this->assertSame($g7->id, AssessmentScheme::for($g7->id)?->grade_level_id);
        $this->assertNull(AssessmentScheme::for($g8->id)?->grade_level_id);

        // Default listed first.
        $this->getJson('/api/v1/assessment-schemes')->assertOk()->assertJsonPath('data.0.grade_level', null);
    }

    public function test_validation_of_components_bands_and_levels(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSESSMENT_VIEW, Permissions::ASSESSMENT_MANAGE]);

        $this->postJson('/api/v1/assessment-schemes', $this->scheme([
            'mode' => 'letters',
            'components' => [['name' => '', 'kind' => 'nope', 'max_score' => 0, 'weight' => 120, 'occurrences' => 13]],
            'bands' => [['letter' => 'A', 'min_percent' => 90], ['letter' => 'a', 'min_percent' => 150]],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'mode', 'components.0.name', 'components.0.kind', 'components.0.max_score', 'components.0.weight',
            'components.0.occurrences', 'bands.1.letter', 'bands.1.min_percent',
        ]);
    }

    public function test_competency_mode_and_the_starter_setups(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSESSMENT_VIEW, Permissions::ASSESSMENT_MANAGE]);
        $g1 = $this->grade('G1', 1);
        $maths = Book::factory()->create(['title' => 'Mathematics']);

        $primary = $this->postJson('/api/v1/assessment-schemes', [
            'grade_level_id' => $g1->id, 'mode' => 'competency', 'name' => 'Primary', 'max_score' => 10,
        ])->assertCreated()->assertJsonPath('data.warnings', ['no_levels', 'no_criteria'])->json('data');

        $this->postJson("/api/v1/assessment-schemes/{$primary['id']}/starter")->assertOk()
            ->assertJsonCount(4, 'data.levels')->assertJsonCount(3, 'data.criteria')
            ->assertJsonPath('data.levels.3.is_passing', false)
            ->assertJsonPath('data.warnings', []);

        // A subject's own criterion.
        $levels = $this->getJson('/api/v1/assessment-schemes')->json('data.0.levels');
        $this->putJson("/api/v1/assessment-schemes/{$primary['id']}", [
            'grade_level_id' => $g1->id, 'mode' => 'competency', 'name' => 'Primary', 'max_score' => 10,
            'criteria' => [['name' => 'Counting', 'name_km' => 'ការរាប់', 'book_id' => $maths->id]],
            'levels' => $levels,
        ])->assertOk()->assertJsonPath('data.criteria.0.book.title', 'Mathematics')->assertJsonCount(4, 'data.levels');

        // Score starter fills an empty score scheme only.
        $secondary = $this->postJson('/api/v1/assessment-schemes', ['mode' => 'score', 'name' => 'Default', 'max_score' => 50])->json('data');
        $this->postJson("/api/v1/assessment-schemes/{$secondary['id']}/starter")->assertOk()
            ->assertJsonPath('data.weights_total', 100)->assertJsonCount(6, 'data.bands')->assertJsonPath('data.bands.0.letter', 'A');
        $this->postJson("/api/v1/assessment-schemes/{$secondary['id']}/starter")->assertOk()->assertJsonCount(2, 'data.components');
    }

    public function test_view_only_cannot_change_and_no_permission_cannot_see(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSESSMENT_VIEW]);
        $this->getJson('/api/v1/assessment-schemes')->assertOk();
        $this->postJson('/api/v1/assessment-schemes', $this->scheme())->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        $this->getJson('/api/v1/assessment-schemes')->assertForbidden();
    }

    // --- Exam schedule ---------------------------------------------------------

    /** @return array{0: AcademicYear, 1: GradeLevel, 2: SchoolClass, 3: SchoolClass, 4: Book, 5: Book} */
    private function examSetup(): array
    {
        $year = AcademicYear::factory()->create(['name' => '2026-2027']);
        $g7 = $this->grade();
        $a = SchoolClass::factory()->create(['name' => '7A', 'grade_level_id' => $g7->id, 'academic_year_id' => $year->id]);
        $b = SchoolClass::factory()->create(['name' => '7B', 'grade_level_id' => $g7->id, 'academic_year_id' => $year->id]);

        return [$year, $g7, $a, $b, Book::factory()->create(['title' => 'Mathematics']), Book::factory()->create(['title' => 'Khmer'])];
    }

    private function exam(array $overrides): array
    {
        return $overrides + ['exam_date' => '2027-03-01', 'start_time' => '07:30', 'end_time' => '09:00'];
    }

    public function test_exams_are_scheduled_for_a_grade_or_a_class_and_listed_by_date(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCHEDULES_VIEW, Permissions::EXAM_SCHEDULES_MANAGE]);
        [$year, $g7, $a, , $maths, $khmer] = $this->examSetup();
        $term = AcademicTerm::query()->create(['academic_year_id' => $year->id, 'name' => 'Semester 1']);

        $this->postJson('/api/v1/exam-schedules', $this->exam([
            'academic_year_id' => $year->id, 'academic_term_id' => $term->id, 'grade_level_id' => $g7->id, 'book_id' => $maths->id,
        ]))->assertCreated()->assertJsonPath('data.grade_level.code', 'G7')->assertJsonPath('data.start_time', '07:30')->assertJsonPath('data.term.name', 'Semester 1');

        // A class exam names its class only.
        $this->postJson('/api/v1/exam-schedules', $this->exam([
            'academic_year_id' => $year->id, 'class_id' => $a->id, 'grade_level_id' => $g7->id, 'book_id' => $khmer->id,
            'exam_date' => '2027-02-28',
        ]))->assertCreated()->assertJsonPath('data.class.name', '7A')->assertJsonPath('data.grade_level', null);

        // Class 7A's list shows its own exam and its grade's.
        $this->getJson("/api/v1/exam-schedules?class_id={$a->id}")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.book.title', 'Khmer');

        $this->postJson('/api/v1/exam-schedules', $this->exam(['academic_year_id' => $year->id, 'book_id' => $maths->id, 'end_time' => '07:00']))
            ->assertUnprocessable()->assertJsonValidationErrors(['grade_level_id', 'class_id', 'end_time']);
    }

    public function test_overlapping_exams_may_not_share_students_room_or_invigilator(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCHEDULES_VIEW, Permissions::EXAM_SCHEDULES_MANAGE]);
        [$year, $g7, $a, $b, $maths, $khmer] = $this->examSetup();
        $room = Classroom::factory()->create(['name' => 'Room 1']);
        $teacher = Staff::factory()->create();

        $first = $this->postJson('/api/v1/exam-schedules', $this->exam([
            'academic_year_id' => $year->id, 'class_id' => $a->id, 'book_id' => $maths->id, 'classroom_id' => $room->id, 'invigilator_staff_id' => $teacher->id,
        ]))->assertCreated()->json('data');

        // The whole grade overlaps 7A.
        $this->postJson('/api/v1/exam-schedules', $this->exam(['academic_year_id' => $year->id, 'grade_level_id' => $g7->id, 'book_id' => $khmer->id, 'start_time' => '08:30', 'end_time' => '10:00']))
            ->assertUnprocessable()->assertJsonValidationErrors('class_id');
        // Another class, same room and invigilator.
        $this->postJson('/api/v1/exam-schedules', $this->exam(['academic_year_id' => $year->id, 'class_id' => $b->id, 'book_id' => $khmer->id, 'classroom_id' => $room->id, 'invigilator_staff_id' => $teacher->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['classroom_id', 'invigilator_staff_id'])->assertJsonMissingValidationErrors('class_id');
        // Right after is fine; another class in another room at the same time is fine.
        $this->postJson('/api/v1/exam-schedules', $this->exam(['academic_year_id' => $year->id, 'grade_level_id' => $g7->id, 'book_id' => $khmer->id, 'start_time' => '09:00', 'end_time' => '10:30']))->assertCreated();
        $this->postJson('/api/v1/exam-schedules', $this->exam(['academic_year_id' => $year->id, 'class_id' => $b->id, 'book_id' => $khmer->id, 'start_time' => '07:30', 'end_time' => '08:30']))->assertCreated();

        // Editing an exam does not clash with itself.
        $this->putJson("/api/v1/exam-schedules/{$first['id']}", $this->exam([
            'academic_year_id' => $year->id, 'class_id' => $a->id, 'book_id' => $maths->id, 'classroom_id' => $room->id, 'end_time' => '08:45',
        ]))->assertOk()->assertJsonPath('data.end_time', '08:45');

        $this->deleteJson("/api/v1/exam-schedules/{$first['id']}")->assertNoContent();
    }

    public function test_exam_schedule_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_SCHEDULES_VIEW]);
        [$year, $g7, , , $maths] = $this->examSetup();

        $this->getJson('/api/v1/exam-schedules')->assertOk();
        $this->postJson('/api/v1/exam-schedules', $this->exam(['academic_year_id' => $year->id, 'grade_level_id' => $g7->id, 'book_id' => $maths->id]))->assertForbidden();
    }
}

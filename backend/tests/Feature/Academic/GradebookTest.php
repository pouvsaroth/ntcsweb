<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\AssessmentScheme;
use App\Models\Book;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Position;
use App\Models\PromotionRule;
use App\Models\ResultSheet;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Examination, gradebook & assessment — step 7b (docs/academics/gradebook.md):
 * grade entry, calculation, validation and class results.
 */
class GradebookTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private AcademicTerm $term;

    private GradeLevel $grade;

    private SchoolClass $class;

    private Book $maths;

    private Book $khmer;

    /** @var list<Student> */
    private array $students = [];

    private AssessmentScheme $scheme;

    /** Grade 7 class 7A with three students, Maths + Khmer in the curriculum, a score setup: monthly ×2 (40%) + exam (60%) out of 50. */
    private function school(array $permissions = [Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER, Permissions::GRADEBOOK_MANAGE_ALL]): void
    {
        $this->actingAsAdminWithPermissions($permissions);
        $year = AcademicYear::factory()->create(['name' => '2026-2027']);
        $this->term = AcademicTerm::query()->create(['academic_year_id' => $year->id, 'name' => 'Semester 1']);
        $this->grade = GradeLevel::query()->create(['code' => 'G7', 'name' => 'Grade 7', 'stage' => 'lower_secondary', 'level_order' => 7]);
        $this->class = SchoolClass::factory()->create(['name' => '7A', 'grade_level_id' => $this->grade->id, 'academic_year_id' => $year->id, 'status' => SchoolClass::STATUS_ACTIVE]);
        $this->maths = Book::factory()->create(['title' => 'Mathematics']);
        $this->khmer = Book::factory()->create(['title' => 'Khmer']);
        foreach ([$this->maths, $this->khmer] as $book) {
            DB::connection('tenant')->table('book_grade_level')->insert(['book_id' => $book->id, 'grade_level_id' => $this->grade->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['Chan', 'Dara', 'Sok'] as $name) {
            $student = Student::factory()->create(['first_name' => $name, 'last_name' => 'K']);
            Enrollment::factory()->forClass($this->class)->forStudent($student)->create(['status' => Enrollment::STATUS_ACTIVE]);
            $this->students[] = $student;
        }
        $this->scheme = AssessmentScheme::query()->create(['grade_level_id' => null, 'mode' => 'score', 'name' => 'Secondary', 'max_score' => 50, 'decimals' => 2]);
        $this->scheme->components()->createMany([
            ['name' => 'Monthly', 'kind' => 'monthly', 'max_score' => 20, 'weight' => 40, 'occurrences' => 2, 'sort_order' => 1],
            ['name' => 'Exam', 'kind' => 'exam', 'max_score' => 100, 'weight' => 60, 'occurrences' => 1, 'sort_order' => 2],
        ]);
        $this->scheme->bands()->createMany([
            ['letter' => 'A', 'min_percent' => 90], ['letter' => 'B', 'min_percent' => 80], ['letter' => 'C', 'min_percent' => 70],
            ['letter' => 'D', 'min_percent' => 60], ['letter' => 'E', 'min_percent' => 50], ['letter' => 'F', 'min_percent' => 0],
        ]);
        PromotionRule::query()->create(['grade_level_id' => null, 'pass_mark' => 50]);
    }

    private function target(?Book $book = null): array
    {
        return ['class_id' => $this->class->id, 'book_id' => ($book ?? $this->maths)->id, 'academic_term_id' => $this->term->id];
    }

    private function cells(Student $s, ?float $m1, ?float $m2, ?float $exam, bool $examAbsent = false): array
    {
        [$monthly, $examC] = $this->scheme->components()->get()->all();

        return [
            ['student_id' => $s->id, 'component_id' => $monthly->id, 'occurrence' => 1, 'score' => $m1],
            ['student_id' => $s->id, 'component_id' => $monthly->id, 'occurrence' => 2, 'score' => $m2],
            ['student_id' => $s->id, 'component_id' => $examC->id, 'occurrence' => 1, 'score' => $exam, 'absent' => $examAbsent],
        ];
    }

    private function save(array $cells, ?Book $book = null)
    {
        return $this->putJson('/api/v1/gradebook/sheet', $this->target($book) + ['cells' => $cells]);
    }

    private function resultOf(array $sheet, Student $s): ?array
    {
        return collect($sheet['students'])->firstWhere('id', $s->id)['result'];
    }

    public function test_the_empty_sheet_lists_the_columns_and_the_class(): void
    {
        $this->school();

        $this->getJson('/api/v1/gradebook/sheet?'.http_build_query($this->target()))->assertOk()
            ->assertJsonPath('data.sheet', null)
            ->assertJsonPath('data.can_enter', true)
            ->assertJsonPath('data.scheme.name', 'Secondary')
            ->assertJsonCount(3, 'data.columns') // Monthly 1, Monthly 2, Exam
            ->assertJsonPath('data.columns.1.occurrence', 2)
            ->assertJsonCount(3, 'data.students')
            ->assertJsonPath('data.students.0.name', 'Chan K');

        $this->getJson('/api/v1/gradebook/classes')->assertOk()->assertJsonPath('data.0.name', '7A')->assertJsonCount(2, 'data.0.subjects');
    }

    public function test_bulk_entry_calculates_percent_score_band_pass_and_rank(): void
    {
        $this->school();
        [$chan, $dara, $sok] = $this->students;

        // Chan: monthly (18+16)/2=17 of 20 → 85% × 40; exam 90% × 60 → 88% → 44.00 of 50, B, passed.
        // Dara: monthly 10, 10 → 50%; exam 40 → 40% → 44%, F, failed.
        // Sok: monthly 20, 20 → 100%; exam 76 → 76% → 85.6% → B. Ranks: Chan 1, Sok... see below.
        $sheet = $this->save([...$this->cells($chan, 18, 16, 90), ...$this->cells($dara, 10, 10, 40), ...$this->cells($sok, 20, 20, 76)])
            ->assertOk()->assertJsonPath('data.sheet.status', 'draft')->json('data');

        $this->assertEquals(['percent' => 88.0, 'score' => 44.0, 'band' => 'B', 'passed' => true, 'rank' => 1, 'complete' => true], $this->resultOf($sheet, $chan));
        $this->assertSame(['F', false, 3], [$this->resultOf($sheet, $dara)['band'], $this->resultOf($sheet, $dara)['passed'], $this->resultOf($sheet, $dara)['rank']]);
        $this->assertSame([85.6, 2], [$this->resultOf($sheet, $sok)['percent'], $this->resultOf($sheet, $sok)['rank']]);
    }

    public function test_one_student_at_a_time_partial_results_and_clearing_a_cell(): void
    {
        $this->school();
        [$chan] = $this->students;
        [$monthly] = $this->scheme->components()->get()->all();

        $sheet = $this->save([['student_id' => $chan->id, 'component_id' => $monthly->id, 'occurrence' => 1, 'score' => 10]])->assertOk()->json('data');
        // Partial: a percent so far, no band, no rank.
        $this->assertEquals(['percent' => 50.0, 'score' => 25.0, 'band' => null, 'passed' => null, 'rank' => null, 'complete' => false], $this->resultOf($sheet, $chan));

        // An empty cell removes the entry.
        $sheet = $this->save([['student_id' => $chan->id, 'component_id' => $monthly->id, 'occurrence' => 1, 'score' => null]])->json('data');
        $this->assertNull($this->resultOf($sheet, $chan)['percent']);
        $this->assertSame(0, ResultSheet::query()->firstOrFail()->entries()->count());
    }

    public function test_absent_counts_as_zero_or_is_left_out(): void
    {
        $this->school();
        [$chan] = $this->students;

        $sheet = $this->save($this->cells($chan, 20, 20, null, examAbsent: true))->json('data');
        $this->assertEquals(40.0, $this->resultOf($sheet, $chan)['percent']); // exam absent = 0
        $this->assertTrue($this->resultOf($sheet, $chan)['complete']);

        $this->scheme->update(['absent_counts_as_zero' => false]);
        $sheet = $this->save($this->cells($chan, 20, 20, null, examAbsent: true))->json('data');
        $this->assertEquals(100.0, $this->resultOf($sheet, $chan)['percent']); // only the monthly tests count
    }

    public function test_scores_are_checked_before_anything_is_saved(): void
    {
        $this->school();
        [$chan, $dara] = $this->students;
        [$monthly] = $this->scheme->components()->get()->all();
        $outsider = Student::factory()->create();

        $this->save([
            ['student_id' => $chan->id, 'component_id' => $monthly->id, 'occurrence' => 1, 'score' => 15],
            ['student_id' => $dara->id, 'component_id' => $monthly->id, 'occurrence' => 1, 'score' => 21],
            ['student_id' => $dara->id, 'component_id' => $monthly->id, 'occurrence' => 3, 'score' => 5],
            ['student_id' => $outsider->id, 'component_id' => $monthly->id, 'occurrence' => 1, 'score' => 5],
            ['student_id' => $chan->id, 'component_id' => $monthly->id, 'occurrence' => 2, 'score' => 'abc'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['cells.1.score', 'cells.2.component_id', 'cells.3.student_id', 'cells.4.score']);

        $this->assertSame(0, ResultSheet::query()->count());
    }

    public function test_competency_mode_levels_per_criterion(): void
    {
        $this->school();
        [$chan, $dara] = $this->students;
        $primary = AssessmentScheme::query()->create(['grade_level_id' => $this->grade->id, 'mode' => 'competency', 'name' => 'Primary', 'max_score' => 10, 'decimals' => 1]);
        $levels = $primary->levels()->createMany([
            ['code' => '4', 'label' => 'Very good', 'points' => 4, 'is_passing' => true],
            ['code' => '3', 'label' => 'Good', 'points' => 3, 'is_passing' => true],
            ['code' => '1', 'label' => 'Needs support', 'points' => 1, 'is_passing' => false],
        ]);
        [$knowledge, $skills] = $primary->criteria()->createMany([['name' => 'Knowledge'], ['name' => 'Skills']])->all();
        $primary->criteria()->create(['name' => 'Reading', 'book_id' => $this->khmer->id]); // not a Maths criterion

        $sheet = $this->getJson('/api/v1/gradebook/sheet?'.http_build_query($this->target()))->assertJsonCount(2, 'data.columns')->json('data');
        $this->assertSame('competency', $sheet['scheme']['mode']);

        $sheet = $this->save([
            ['student_id' => $chan->id, 'criterion_id' => $knowledge->id, 'level_id' => $levels[0]->id],
            ['student_id' => $chan->id, 'criterion_id' => $skills->id, 'level_id' => $levels[1]->id],
            ['student_id' => $dara->id, 'criterion_id' => $knowledge->id, 'level_id' => $levels[0]->id],
            ['student_id' => $dara->id, 'criterion_id' => $skills->id, 'level_id' => $levels[2]->id],
        ])->assertOk()->json('data');

        // Chan: (4+3)/2 = 3.5 of 4 → 87.5%, 8.8 of 10, nearest level 4 (tie goes to the first), passed.
        $this->assertSame([87.5, 8.8, true], [$this->resultOf($sheet, $chan)['percent'], $this->resultOf($sheet, $chan)['score'], $this->resultOf($sheet, $chan)['passed']]);
        // Dara has a level that does not pass.
        $this->assertFalse($this->resultOf($sheet, $dara)['passed']);
    }

    public function test_validation_lists_what_is_missing_or_wrong(): void
    {
        $this->school();
        [$chan] = $this->students;
        $this->save($this->cells($chan, 20, 20, null, examAbsent: true));

        $rows = collect($this->getJson("/api/v1/gradebook/validation?academic_term_id={$this->term->id}")->assertOk()->json('data'));
        $maths = $rows->firstWhere('subject.title', 'Mathematics');
        $khmer = $rows->firstWhere('subject.title', 'Khmer');

        $this->assertSame([9, 3, 6, 1], [$maths['expected'], $maths['entered'], $maths['missing'], $maths['absent_without_remark']]);
        $this->assertEqualsCanonicalizing(['missing', 'absent_without_remark'], $maths['issues']);
        $this->assertEqualsCanonicalizing(['not_started', 'missing'], $khmer['issues']);

        // Weights changed after entry: flagged.
        $this->scheme->components()->first()->update(['weight' => 30]);
        $maths = collect($this->getJson("/api/v1/gradebook/validation?academic_term_id={$this->term->id}")->json('data'))->firstWhere('subject.title', 'Mathematics');
        $this->assertContains('weights_not_100', $maths['issues']);
    }

    public function test_class_results_average_the_subjects_and_rank_the_students(): void
    {
        $this->school();
        [$chan, $dara, $sok] = $this->students;
        $this->save([...$this->cells($chan, 18, 16, 90), ...$this->cells($dara, 10, 10, 40), ...$this->cells($sok, 20, 20, 76)]);
        $this->save([...$this->cells($chan, 20, 20, 100), ...$this->cells($dara, 20, 20, 100), ...$this->cells($sok, 10, 10, 50)], $this->khmer);

        $data = $this->getJson("/api/v1/gradebook/results?academic_term_id={$this->term->id}&class_id={$this->class->id}")->assertOk()->json('data');
        $rows = collect($data['students'])->keyBy('id');

        $this->assertCount(2, $data['subjects']);
        $this->assertEquals([94, 47, 'A', 1], [$rows[$chan->id]['average'], $rows[$chan->id]['score'], $rows[$chan->id]['band'], $rows[$chan->id]['rank']]);
        $this->assertEquals([72, 1], [$rows[$dara->id]['average'], $rows[$dara->id]['failed_subjects']]);
        $this->assertEquals([67.8, 3, 2], [$rows[$sok->id]['average'], $rows[$sok->id]['rank'], $rows[$dara->id]['rank']]);
    }

    public function test_a_teacher_enters_only_their_own_subject_and_sees_only_their_classes(): void
    {
        $this->school([Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER]);
        $other = SchoolClass::factory()->create(['name' => '7B', 'grade_level_id' => $this->grade->id]);
        [$chan] = $this->students;

        // Not linked to any class yet.
        $this->getJson('/api/v1/gradebook/classes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/gradebook/sheet?'.http_build_query($this->target()))->assertForbidden();

        // Assigned Maths in 7A.
        $role = Role::factory()->forTenant($this->tenant)->system()->create(['slug' => Role::TEACHER, 'name' => 'Teacher', 'level' => Role::LEVELS[Role::TEACHER]]);
        $me = Staff::factory()->create(['position_id' => Position::factory()->create(['role_id' => $role->id])->id]);
        $me->forceFill(['user_id' => $this->admin->id])->save();
        DB::connection('tenant')->table('teacher_assignments')->insert(['staff_id' => $me->id, 'class_id' => $this->class->id, 'book_id' => $this->maths->id, 'created_at' => now(), 'updated_at' => now()]);

        $classes = $this->getJson('/api/v1/gradebook/classes')->assertOk()->assertJsonCount(1, 'data')->json('data.0.subjects');
        $this->assertSame([true, false], collect($classes)->sortBy('title')->pluck('can_enter')->reverse()->values()->all());

        $this->save($this->cells($chan, 10, 10, 50))->assertOk();
        $this->save($this->cells($chan, 10, 10, 50), $this->khmer)->assertForbidden();
        $this->getJson('/api/v1/gradebook/sheet?'.http_build_query($this->target($this->khmer)))->assertOk()->assertJsonPath('data.can_enter', false);
        $this->getJson("/api/v1/gradebook/results?academic_term_id={$this->term->id}&class_id={$other->id}")->assertForbidden();
    }

    public function test_a_locked_sheet_and_a_used_component_cannot_change(): void
    {
        $this->school();
        [$chan] = $this->students;
        $this->save($this->cells($chan, 10, 10, 50))->assertOk();

        // A component with scores cannot be removed from the setup.
        $this->actingAsAdminWithPermissions([Permissions::ASSESSMENT_VIEW, Permissions::ASSESSMENT_MANAGE]);
        $components = $this->scheme->components()->get();
        $this->putJson("/api/v1/assessment-schemes/{$this->scheme->id}", [
            'mode' => 'score', 'name' => 'Secondary', 'max_score' => 50,
            'components' => [['id' => $components[1]->id, 'name' => 'Exam', 'kind' => 'exam', 'max_score' => 100, 'weight' => 100, 'occurrences' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('scheme');

        // Submitted (7c) = locked.
        $this->actingAsAdminWithPermissions([Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER, Permissions::GRADEBOOK_MANAGE_ALL]);
        ResultSheet::query()->firstOrFail()->update(['status' => ResultSheet::STATUS_PENDING]);
        $this->save($this->cells($chan, 20, 20, 100))->assertUnprocessable()->assertJsonValidationErrors('sheet');
        $this->getJson('/api/v1/gradebook/sheet?'.http_build_query($this->target()))->assertJsonPath('data.can_enter', false);
    }
}

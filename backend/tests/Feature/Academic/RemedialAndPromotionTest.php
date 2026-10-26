<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\AssessmentScheme;
use App\Models\Book;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\RemedialSession;
use App\Models\ResultSheet;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Examination, gradebook & assessment — step 7e (docs/academics/gradebook.md):
 * Remedial Teaching, and Promote students reading approved gradebook results.
 */
class RemedialAndPromotionTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private AcademicTerm $s1;

    private AcademicTerm $s2;

    private SchoolClass $class;

    private Book $maths;

    private Book $khmer;

    /** @var list<Student> */
    private array $students = [];

    private AssessmentScheme $scheme;

    /** 7A (Grade 7), Chan and Dara, Maths + Khmer, one exam out of 100; two semesters. */
    private function school(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER, Permissions::GRADEBOOK_MANAGE_ALL, Permissions::STUDENT_PROMOTIONS_VIEW,
        ]);
        $year = AcademicYear::factory()->create(['name' => '2026-2027']);
        $this->s1 = AcademicTerm::query()->create(['academic_year_id' => $year->id, 'name' => 'Semester 1', 'sort_order' => 1]);
        $this->s2 = AcademicTerm::query()->create(['academic_year_id' => $year->id, 'name' => 'Semester 2', 'sort_order' => 2]);
        $grade = GradeLevel::query()->create(['code' => 'G7', 'name' => 'Grade 7', 'level_order' => 7]);
        $this->class = SchoolClass::factory()->create(['name' => '7A', 'grade_level_id' => $grade->id, 'academic_year_id' => $year->id]);
        $this->maths = Book::factory()->create(['title' => 'Mathematics']);
        $this->khmer = Book::factory()->create(['title' => 'Khmer']);
        foreach ([$this->maths, $this->khmer] as $b) {
            DB::connection('tenant')->table('book_grade_level')->insert(['book_id' => $b->id, 'grade_level_id' => $grade->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['Chan', 'Dara'] as $name) {
            $s = Student::factory()->create(['first_name' => $name, 'last_name' => 'K']);
            Enrollment::factory()->forClass($this->class)->forStudent($s)->create(['status' => Enrollment::STATUS_ACTIVE]);
            $this->students[] = $s;
        }
        $this->scheme = AssessmentScheme::query()->create(['mode' => 'score', 'name' => 'Secondary', 'max_score' => 100, 'decimals' => 2]);
        $this->scheme->components()->create(['name' => 'Exam', 'kind' => 'exam', 'max_score' => 100, 'weight' => 100, 'occurrences' => 1]);
        $this->scheme->bands()->createMany([['letter' => 'A', 'min_percent' => 80], ['letter' => 'B', 'min_percent' => 50], ['letter' => 'F', 'min_percent' => 0]]);
    }

    /** Enter Chan's and Dara's exam for a subject and term, optionally approved. */
    private function results(Book $book, AcademicTerm $term, array $scores, bool $approve = true): void
    {
        $component = $this->scheme->components()->first();
        $this->putJson('/api/v1/gradebook/sheet', ['class_id' => $this->class->id, 'book_id' => $book->id, 'academic_term_id' => $term->id, 'cells' => [
            ['student_id' => $this->students[0]->id, 'component_id' => $component->id, 'occurrence' => 1, 'score' => $scores[0]],
            ['student_id' => $this->students[1]->id, 'component_id' => $component->id, 'occurrence' => 1, 'score' => $scores[1]],
        ]])->assertOk();
        if ($approve) {
            ResultSheet::query()->where('book_id', $book->id)->where('academic_term_id', $term->id)->update(['status' => ResultSheet::STATUS_APPROVED]);
        }
    }

    public function test_students_below_the_pass_mark_and_a_remedial_session(): void
    {
        $this->school();
        [$chan, $dara] = $this->students;
        $this->results($this->maths, $this->s1, [90, 40]);
        $this->results($this->khmer, $this->s1, [45, 70], approve: false); // drafts count too

        $url = "/api/v1/gradebook/remedial?class_id={$this->class->id}&academic_term_id={$this->s1->id}";
        $data = $this->getJson($url)->assertOk()->json('data');
        $this->assertEquals(50, $data['pass_mark']);
        // Subjects A–Z: Khmer (Chan 45), then Mathematics (Dara 40).
        $this->assertSame([['Khmer', 'Chan K', 45], ['Mathematics', 'Dara K', 40]], array_map(fn ($r) => [$r['subject']['title'], $r['student']['name'], (int) $r['percent']], $data['below']));
        $this->assertSame([0, 0], array_column($data['below'], 'sessions'));

        $created = $this->postJson('/api/v1/gradebook/remedial', [
            'class_id' => $this->class->id, 'academic_term_id' => $this->s1->id, 'book_id' => $this->maths->id,
            'session_date' => '2026-11-02', 'start_time' => '14:00', 'end_time' => '15:00', 'topic' => 'Fractions',
            'students' => [['student_id' => $dara->id]],
        ])->assertCreated()->json('data');
        $this->assertSame('Fractions', $created['topic']);
        $this->assertEquals(40, $created['students'][0]['percent_before']);
        $this->assertTrue($created['can_edit']);

        // Record the outcome.
        $this->putJson("/api/v1/gradebook/remedial/{$created['id']}", [
            'class_id' => $this->class->id, 'academic_term_id' => $this->s1->id, 'book_id' => $this->maths->id,
            'session_date' => '2026-11-02', 'topic' => 'Fractions',
            'students' => [['student_id' => $dara->id, 'attended' => true, 'outcome' => 'improved', 'remark' => 'Much better']],
        ])->assertOk()->assertJsonPath('data.students.0.outcome', 'improved')->assertJsonPath('data.students.0.percent_before', 40);

        $maths = collect($this->getJson($url)->assertOk()->json('data.below'))->firstWhere('subject.title', 'Mathematics');
        $this->assertSame([1, 'improved'], [$maths['sessions'], $maths['last_outcome']]);

        // A student from another class, an unknown outcome: refused.
        $other = Student::factory()->create();
        $this->postJson('/api/v1/gradebook/remedial', [
            'class_id' => $this->class->id, 'academic_term_id' => $this->s1->id, 'book_id' => $this->maths->id,
            'session_date' => '2026-11-03', 'topic' => 'Again', 'students' => [['student_id' => $other->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('students');
        $this->postJson('/api/v1/gradebook/remedial', [
            'class_id' => $this->class->id, 'academic_term_id' => $this->s1->id, 'book_id' => $this->maths->id,
            'session_date' => '2026-11-03', 'topic' => 'Again', 'students' => [['student_id' => $chan->id, 'outcome' => 'great']],
        ])->assertUnprocessable()->assertJsonValidationErrors('students.0.outcome');

        $this->deleteJson("/api/v1/gradebook/remedial/{$created['id']}")->assertNoContent();
        $this->assertSame(0, RemedialSession::query()->count());
    }

    public function test_remedial_teaching_follows_gradebook_access(): void
    {
        $this->school();
        $this->results($this->maths, $this->s1, [90, 40]);
        $session = RemedialSession::query()->create([
            'class_id' => $this->class->id, 'book_id' => $this->maths->id, 'academic_term_id' => $this->s1->id,
            'session_date' => '2026-11-02', 'topic' => 'Fractions',
        ]);

        // A gradebook viewer who teaches no class: nothing.
        $this->actingAsAdminWithPermissions([Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER]);
        $this->getJson("/api/v1/gradebook/remedial?class_id={$this->class->id}&academic_term_id={$this->s1->id}")->assertForbidden();
        $this->deleteJson("/api/v1/gradebook/remedial/{$session->id}")->assertForbidden();

        // An approver sees every class but records nothing.
        $this->actingAsAdminWithPermissions([Permissions::GRADEBOOK_APPROVE]);
        $data = $this->getJson("/api/v1/gradebook/remedial?class_id={$this->class->id}&academic_term_id={$this->s1->id}")->assertOk()->json('data');
        $this->assertFalse($data['sessions'][0]['can_edit']);
        $this->postJson('/api/v1/gradebook/remedial', [
            'class_id' => $this->class->id, 'academic_term_id' => $this->s1->id, 'book_id' => $this->maths->id,
            'session_date' => '2026-11-03', 'topic' => 'Again', 'students' => [['student_id' => $this->students[1]->id]],
        ])->assertForbidden();
    }

    public function test_promote_students_reads_approved_gradebook_results(): void
    {
        $this->school();
        $this->results($this->maths, $this->s1, [90, 40]);
        $this->results($this->maths, $this->s2, [80, 50]);
        $this->results($this->khmer, $this->s1, [10, 10], approve: false); // not approved: left out

        $data = $this->getJson("/api/v1/student-promotions?class_id={$this->class->id}")->assertOk()->json('data');
        $this->assertSame('gradebook', $data['source']);
        $this->assertSame(1, $data['unapproved_sheets']);
        $rows = collect($data['students'])->keyBy('student_id');
        [$chan, $dara] = [$this->students[0]->id, $this->students[1]->id];
        // Maths over the year: Chan (90 + 80) / 2 = 85; Dara (40 + 50) / 2 = 45, below the pass mark of 50.
        $this->assertEquals([85, 1, 0], [$rows[$chan]['average'], $rows[$chan]['subjects'], $rows[$chan]['failed_subjects']]);
        $this->assertEquals([45, 1, 1], [$rows[$dara]['average'], $rows[$dara]['subjects'], $rows[$dara]['failed_subjects']]);
    }

    public function test_a_class_without_gradebook_results_keeps_exam_scores(): void
    {
        $this->school();

        $data = $this->getJson("/api/v1/student-promotions?class_id={$this->class->id}")->assertOk()->json('data');
        $this->assertSame('exams', $data['source']);
        $this->assertSame(0, $data['unapproved_sheets']);
        $this->assertNull($data['students'][0]['average']);
    }
}

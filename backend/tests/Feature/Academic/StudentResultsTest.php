<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\AssessmentScheme;
use App\Models\Book;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\ResultSheet;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Examination, gradebook & assessment — step 7d (docs/academics/gradebook.md):
 * transcript, progress, report cards, score sheet, the student's own results.
 */
class StudentResultsTest extends TestCase
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

    /** 7A, two students, Maths + Khmer, one exam out of 100 shown on a scale of 50; two semesters. */
    private function school(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER, Permissions::GRADEBOOK_MANAGE_ALL]);
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
        $this->scheme = AssessmentScheme::query()->create(['mode' => 'score', 'name' => 'Secondary', 'max_score' => 50, 'decimals' => 2]);
        $this->scheme->components()->create(['name' => 'Exam', 'kind' => 'exam', 'max_score' => 100, 'weight' => 100, 'occurrences' => 1]);
        $this->scheme->bands()->createMany([['letter' => 'A', 'min_percent' => 80], ['letter' => 'B', 'min_percent' => 50], ['letter' => 'F', 'min_percent' => 0]]);
    }

    /** Enter the two students' exam for a subject and term, optionally approved. */
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

    public function test_the_transcript_goes_year_by_year_and_term_by_term(): void
    {
        $this->school();
        [$chan] = $this->students;
        $this->results($this->maths, $this->s1, [90, 40]);
        $this->results($this->khmer, $this->s1, [70, 60]);
        $this->results($this->maths, $this->s2, [80, 50]);
        $this->results($this->khmer, $this->s2, [60, 70], approve: false); // not yet approved

        $all = $this->getJson("/api/v1/gradebook/transcript?student_id={$chan->id}")->assertOk()->json('data');
        $year = $all['years'][0];
        $this->assertSame('2026-2027', $year['academic_year']['name']);
        $this->assertSame(['Semester 1', 'Semester 2'], array_column(array_column($year['terms'], 'term'), 'name'));
        // Semester 1: (90 + 70) / 2 = 80% → 40 of 50, A, rank 1 of 2.
        $this->assertEquals([80, 40, 'A', 1, 2], [$year['terms'][0]['average'], $year['terms'][0]['score'], $year['terms'][0]['band'], $year['terms'][0]['rank'], $year['terms'][0]['class_size']]);
        // Year (subjects A–Z): Khmer (70 + 60) / 2 = 65, Maths (90 + 80) / 2 = 85; year average = average of the terms (80, 70) = 75.
        $this->assertEquals([65, 85], array_column($year['year']['subjects'], 'percent'));
        $this->assertEquals(75, $year['year']['average']);

        // Approved only: Semester 2 has only Maths.
        $official = $this->getJson("/api/v1/gradebook/transcript?student_id={$chan->id}&approved_only=1")->assertOk()->json('data.years.0.terms.1');
        $this->assertSame(['Mathematics'], array_column($official['subjects'], 'title'));
    }

    public function test_progress_for_a_student_and_a_class(): void
    {
        $this->school();
        [$chan] = $this->students;
        $this->results($this->maths, $this->s1, [90, 40]);
        $this->results($this->maths, $this->s2, [70, 60], approve: false);

        $p = $this->getJson("/api/v1/gradebook/progress/student?student_id={$chan->id}")->assertOk()->json('data');
        $this->assertCount(2, $p['points']);
        $this->assertEquals([90, 70], [$p['points'][0]['values'][$this->maths->id], $p['points'][1]['values'][$this->maths->id]]);

        $c = $this->getJson("/api/v1/gradebook/progress/class?class_id={$this->class->id}")->assertOk()->json('data');
        $this->assertEquals([65, 65], [$c['points'][0]['values'][$this->maths->id], $c['points'][1]['values'][$this->maths->id]]);
        $this->assertSame(['Semester 1', 'Semester 2'], array_column($c['points'], 'label'));
    }

    public function test_report_cards_and_transcript_are_pdfs(): void
    {
        $this->school();
        $this->results($this->maths, $this->s1, [90, 40]);

        $this->get("/api/v1/gradebook/export/report-cards?class_id={$this->class->id}&academic_term_id={$this->s1->id}")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $this->get("/api/v1/gradebook/transcript/pdf?student_id={$this->students[0]->id}")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_the_score_sheet_is_an_excel_file_with_every_subject(): void
    {
        $this->school();
        $this->results($this->maths, $this->s1, [90, 40]);
        $this->results($this->khmer, $this->s1, [70, 60], approve: false);

        $response = $this->get("/api/v1/gradebook/export/score-sheet?class_id={$this->class->id}&academic_term_id={$this->s1->id}")->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->getContent());

        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();
        @unlink($path);

        $this->assertSame(['Results', 'Khmer', 'Mathematics'], array_keys($sheets));
        // The reader skips the blank spacer row: title, class line, header, students.
        $header = $sheets['Results'][2];
        $this->assertSame(['No.', 'ID', 'Student', 'Khmer (draft)', 'Mathematics (approved)'], array_slice($header, 0, 5));
        // Chan: Khmer 35, Maths 45 (of 50) → 40, first.
        $chan = $sheets['Results'][3];
        $this->assertSame('Chan K', $chan[2]);
        $this->assertEquals([35, 45, 40], [$chan[3], $chan[4], $chan[5]]);
        $this->assertEquals(90, $sheets['Mathematics'][2][3]);
    }

    public function test_access_follows_the_classes_and_a_student_sees_only_approved_own_results(): void
    {
        $this->school();
        [$chan] = $this->students;
        $this->results($this->maths, $this->s1, [90, 40]);
        $this->results($this->khmer, $this->s1, [70, 60], approve: false);

        // A gradebook viewer who teaches no class sees no student.
        $this->actingAsAdminWithPermissions([Permissions::GRADEBOOK_VIEW]);
        $this->getJson("/api/v1/gradebook/transcript?student_id={$chan->id}")->assertForbidden();
        $this->get("/api/v1/gradebook/export/score-sheet?class_id={$this->class->id}&academic_term_id={$this->s1->id}")->assertForbidden();

        // The student: own approved results only.
        $user = User::factory()->forTenant($this->tenant)->create();
        $chan->update(['user_id' => $user->id]);
        $this->actingAsTenantUser($user);
        $mine = $this->getJson('/api/v1/my-results')->assertOk()->json('data');
        $this->assertSame('Chan K', $mine['student']['name']);
        $this->assertSame(['Mathematics'], array_column($mine['years'][0]['terms'][0]['subjects'], 'title'));
    }
}

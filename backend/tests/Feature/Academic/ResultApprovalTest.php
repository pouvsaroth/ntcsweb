<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalGroup;
use App\Models\AssessmentScheme;
use App\Models\Book;
use App\Models\Enrollment;
use App\Models\GradeCorrection;
use App\Models\GradeEntryHistory;
use App\Models\GradeLevel;
use App\Models\ResultSheet;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SubjectResult;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Approvals\DocumentType;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Examination, gradebook & assessment — step 7c (docs/academics/gradebook.md):
 * result approval and grade corrections through eApprovals.
 */
class ResultApprovalTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private User $teacher;

    private AcademicTerm $term;

    private SchoolClass $class;

    private Book $maths;

    /** @var list<Student> */
    private array $students = [];

    private AssessmentScheme $scheme;

    /** A teacher (view + enter, all classes) of 7A: two students, one exam out of 100. */
    private function school(): void
    {
        $this->teacher = $this->actingAsAdminWithPermissions([Permissions::GRADEBOOK_VIEW, Permissions::GRADEBOOK_ENTER, Permissions::GRADEBOOK_MANAGE_ALL]);
        $year = AcademicYear::factory()->create();
        $this->term = AcademicTerm::query()->create(['academic_year_id' => $year->id, 'name' => 'Semester 1']);
        $grade = GradeLevel::query()->create(['code' => 'G7', 'name' => 'Grade 7', 'level_order' => 7]);
        $this->class = SchoolClass::factory()->create(['name' => '7A', 'grade_level_id' => $grade->id, 'academic_year_id' => $year->id]);
        $this->maths = Book::factory()->create(['title' => 'Mathematics']);
        DB::connection('tenant')->table('book_grade_level')->insert(['book_id' => $this->maths->id, 'grade_level_id' => $grade->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['Chan', 'Dara'] as $name) {
            $s = Student::factory()->create(['first_name' => $name]);
            Enrollment::factory()->forClass($this->class)->forStudent($s)->create(['status' => Enrollment::STATUS_ACTIVE]);
            $this->students[] = $s;
        }
        $this->scheme = AssessmentScheme::query()->create(['mode' => 'score', 'name' => 'Secondary', 'max_score' => 100, 'decimals' => 2]);
        $this->scheme->components()->create(['name' => 'Exam', 'kind' => 'exam', 'max_score' => 100, 'weight' => 100, 'occurrences' => 1]);
    }

    private function target(): array
    {
        return ['class_id' => $this->class->id, 'book_id' => $this->maths->id, 'academic_term_id' => $this->term->id];
    }

    private function enter(array $scores): void
    {
        $component = $this->scheme->components()->first();
        $cells = [];
        foreach ($scores as $i => $score) {
            $cells[] = ['student_id' => $this->students[$i]->id, 'component_id' => $component->id, 'occurrence' => 1, 'score' => $score];
        }
        $this->putJson('/api/v1/gradebook/sheet', $this->target() + ['cells' => $cells])->assertOk();
    }

    private function sheet(): ResultSheet
    {
        return ResultSheet::query()->firstOrFail();
    }

    /** Another account of the same school, with these permissions. */
    private function approver(array $permissions = [Permissions::GRADEBOOK_APPROVE]): User
    {
        $role = Role::factory()->forTenant($this->tenant)->create(['name' => 'Academic head', 'slug' => 'academic-head-'.uniqid()]);
        $role->permissions()->attach(\App\Models\Permission::query()->whereIn('slug', $permissions)->pluck('id'));
        $user = User::factory()->forTenant($this->tenant)->create();
        $user->attachRoles($role);

        return $user;
    }

    public function test_results_are_submitted_once_complete_then_approved_and_locked(): void
    {
        $this->school();
        $approver = $this->approver();

        $this->enter([80]); // Dara still missing
        $this->postJson('/api/v1/gradebook/sheets/submit', $this->target())->assertUnprocessable()->assertJsonValidationErrors('sheet');

        $this->enter([80, 45]);
        $this->postJson('/api/v1/gradebook/sheets/submit', $this->target())->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame($this->teacher->id, $this->sheet()->requested_by);
        $this->assertTrue(UserNotification::query()->where('recipient_id', $approver->id)->where('type', 'result_sheet_submitted')->exists());

        // Locked while waiting; the teacher cannot approve their own.
        $this->putJson('/api/v1/gradebook/sheet', $this->target() + ['cells' => [['student_id' => $this->students[0]->id, 'component_id' => $this->scheme->components()->first()->id, 'occurrence' => 1, 'score' => 99]]])
            ->assertUnprocessable()->assertJsonValidationErrors('sheet');
        $this->postJson("/api/v1/gradebook/sheets/{$this->sheet()->id}/approve")->assertForbidden();

        // In the approver's Approvals queue.
        $this->actingAsTenantUser($approver);
        $queue = $this->getJson('/api/v1/gradebook/sheets?approval_queue=1')->assertOk()->json('data');
        $this->assertSame(['pending', true], [$queue[0]['status'], $queue[0]['can_decide']]);

        $this->postJson("/api/v1/gradebook/sheets/{$this->sheet()->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue(UserNotification::query()->where('recipient_id', $this->teacher->id)->where('type', 'result_sheet_approved')->exists());
    }

    public function test_returned_results_can_be_changed_and_submitted_again(): void
    {
        $this->school();
        $approver = $this->approver();
        $this->enter([80, 45]);
        $this->postJson('/api/v1/gradebook/sheets/submit', $this->target())->assertOk();

        $this->actingAsTenantUser($approver);
        $this->postJson("/api/v1/gradebook/sheets/{$this->sheet()->id}/return", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/gradebook/sheets/{$this->sheet()->id}/return", ['reason' => 'Check Dara'])->assertOk()->assertJsonPath('data.status', 'returned');

        $this->actingAsTenantUser($this->teacher);
        $this->getJson('/api/v1/gradebook/sheet?'.http_build_query($this->target()))
            ->assertJsonPath('data.sheet.status', 'returned')->assertJsonPath('data.sheet.decision_note', 'Check Dara')->assertJsonPath('data.can_enter', true);
        $this->enter([80, 55]);
        $this->postJson('/api/v1/gradebook/sheets/submit', $this->target())->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertNull($this->sheet()->decision_note);
    }

    public function test_an_approval_flow_takes_each_step_in_turn(): void
    {
        $this->school();
        $first = $this->approver([]); // flow members need no permission
        $second = $this->approver([]);
        foreach ([$first, $second] as $i => $user) {
            $group = ApprovalGroup::factory()->create(['name' => 'Step '.($i + 1)]);
            $group->members()->create(['user_id' => $user->id]);
            ApprovalFlowStep::query()->create(['document_type' => DocumentType::RESULT_SHEET, 'step_order' => $i + 1, 'approval_group_id' => $group->id]);
        }
        $this->enter([80, 45]);
        $this->postJson('/api/v1/gradebook/sheets/submit', $this->target())->assertOk();

        // Step 2 cannot go first.
        $this->actingAsTenantUser($second);
        $this->postJson("/api/v1/gradebook/sheets/{$this->sheet()->id}/approve")->assertForbidden();

        $this->actingAsTenantUser($first);
        $this->postJson("/api/v1/gradebook/sheets/{$this->sheet()->id}/approve")->assertOk()->assertJsonPath('data.status', 'pending');

        $this->actingAsTenantUser($second);
        $this->getJson('/api/v1/gradebook/sheets?approval_queue=1')->assertOk()->assertJsonPath('data.0.approval_flow.step', 2);
        $this->postJson("/api/v1/gradebook/sheets/{$this->sheet()->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_a_correction_changes_an_approved_score_with_history_and_recalculation(): void
    {
        $this->school();
        $approver = $this->approver();
        $component = $this->scheme->components()->first();
        $this->enter([80, 45]);

        // Not approved yet: no correction (edit it, or have it returned).
        $ask = ['student_id' => $this->students[1]->id, 'component_id' => $component->id, 'occurrence' => 1, 'score' => 55, 'reason' => 'Page 3 was not marked'];
        $this->postJson('/api/v1/gradebook/corrections', $this->target() + $ask)->assertUnprocessable()->assertJsonValidationErrors('sheet');

        $this->postJson('/api/v1/gradebook/sheets/submit', $this->target())->assertOk();
        $this->actingAsTenantUser($approver);
        $this->postJson("/api/v1/gradebook/sheets/{$this->sheet()->id}/approve")->assertOk();
        $this->actingAsTenantUser($this->teacher);

        $this->postJson('/api/v1/gradebook/corrections', ['score' => 101] + $this->target() + $ask)->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->postJson('/api/v1/gradebook/corrections', ['score' => 45] + $this->target() + $ask)->assertUnprocessable()->assertJsonValidationErrors('score'); // unchanged
        $id = $this->postJson('/api/v1/gradebook/corrections', $this->target() + $ask)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/gradebook/corrections', $this->target() + $ask)->assertUnprocessable(); // one at a time
        $this->getJson('/api/v1/gradebook/sheet?'.http_build_query($this->target()))
            ->assertJsonPath('data.can_request_correction', true)
            ->assertJsonPath('data.pending_corrections.0', $this->students[1]->id.'|'.$component->id.'-1');

        $this->actingAsTenantUser($approver);
        $row = $this->getJson('/api/v1/gradebook/corrections?approval_queue=1')->assertOk()->json('data.0');
        $this->assertSame(['45', '55', 'Page 3 was not marked', true], [$row['old'], $row['new'], $row['reason'], $row['can_decide']]);
        $this->postJson("/api/v1/gradebook/corrections/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        // Entry changed, recalculated, history written; the sheet stays approved.
        $result = SubjectResult::query()->where('student_id', $this->students[1]->id)->firstOrFail();
        $this->assertEquals(55, $result->percent);
        $history = GradeEntryHistory::query()->sole();
        $this->assertSame(['45.00', '55.00', $id], [$history->old_score, $history->new_score, $history->grade_correction_id]);
        $this->assertSame('approved', $this->sheet()->fresh()->status);
        $this->getJson('/api/v1/gradebook/history?'.http_build_query($this->target()))->assertOk()
            ->assertJsonPath('data.0.old', '45')->assertJsonPath('data.0.new', '55')->assertJsonPath('data.0.correction', 'GC-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT));
    }

    public function test_a_correction_can_be_rejected_or_withdrawn(): void
    {
        $this->school();
        $approver = $this->approver();
        $component = $this->scheme->components()->first();
        $this->enter([80, 45]);
        $this->postJson('/api/v1/gradebook/sheets/submit', $this->target());
        $this->actingAsTenantUser($approver);
        $this->postJson("/api/v1/gradebook/sheets/{$this->sheet()->id}/approve");
        $this->actingAsTenantUser($this->teacher);

        $ask = ['student_id' => $this->students[0]->id, 'component_id' => $component->id, 'occurrence' => 1, 'absent' => true, 'reason' => 'Was absent, marked by mistake'];
        $id = $this->postJson('/api/v1/gradebook/corrections', $this->target() + $ask)->assertCreated()->json('data.id');

        $this->actingAsTenantUser($approver);
        $this->postJson("/api/v1/gradebook/corrections/{$id}/reject", ['reason' => 'Attendance shows present'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(0, GradeEntryHistory::query()->count());
        $this->assertEquals(80, SubjectResult::query()->where('student_id', $this->students[0]->id)->value('percent'));

        $this->actingAsTenantUser($this->teacher);
        $id2 = $this->postJson('/api/v1/gradebook/corrections', $this->target() + $ask)->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/gradebook/corrections/{$id2}")->assertNoContent();
        $this->assertSame(1, GradeCorrection::query()->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Book;
use App\Models\CurriculumLesson;
use App\Models\GradeLevel;
use App\Models\LessonPlan;
use App\Models\Position;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Academic > Lesson plans (docs/academics/lesson-plans.md). */
class LessonPlanTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private Staff $me;

    private SchoolClass $myClass;

    private SchoolClass $otherClass;

    private Book $math;

    /** Signed in as a teacher (write only) who teaches 7A, not 8A. */
    private function asTeacher(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LESSON_PLANS_WRITE]);
        $role = Role::factory()->forTenant($this->tenant)->system()->create(['slug' => Role::TEACHER, 'name' => 'Teacher', 'level' => Role::LEVELS[Role::TEACHER]]);
        $position = Position::factory()->create(['role_id' => $role->id]);
        $this->me = Staff::factory()->create(['position_id' => $position->id, 'status' => Staff::STATUS_ACTIVE]);
        $this->me->forceFill(['user_id' => $this->admin->id])->save();
        $this->myClass = SchoolClass::factory()->create(['name' => '7A', 'status' => SchoolClass::STATUS_ACTIVE]);
        $this->myClass->teachers()->attach($this->me->id);
        $this->otherClass = SchoolClass::factory()->create(['name' => '8A', 'status' => SchoolClass::STATUS_ACTIVE]);
        $this->math = Book::factory()->create(['title' => 'Math']);
    }

    private function plan(array $over = []): array
    {
        return array_merge([
            'class_id' => $this->myClass->id, 'book_id' => $this->math->id, 'planned_date' => now()->addDay()->toDateString(),
            'title' => 'Fractions', 'objectives' => 'Add fractions', 'materials' => 'Textbook p. 12', 'activities' => 'Pair work',
            'homework' => 'Exercise 1–5', 'homework_due' => now()->addDays(3)->toDateString(),
        ], $over);
    }

    public function test_a_teacher_plans_for_their_own_classes_only(): void
    {
        $this->asTeacher();

        $id = $this->postJson('/api/v1/lesson-plans/plans', $this->plan())->assertCreated()->json('data.id');
        $this->assertSame($this->me->id, LessonPlan::query()->findOrFail($id)->staff_id);
        $this->postJson('/api/v1/lesson-plans/plans', $this->plan(['class_id' => $this->otherClass->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('class_id');

        $this->getJson("/api/v1/lesson-plans/plans/{$id}")->assertOk()
            ->assertJsonPath('data.objectives', 'Add fractions')->assertJsonPath('data.can_edit', true);
        $this->getJson('/api/v1/lesson-plans/options')->assertOk()->assertJsonCount(1, 'data.classes');
    }

    public function test_submit_review_return_and_approve(): void
    {
        $this->asTeacher();
        $id = $this->postJson('/api/v1/lesson-plans/plans', $this->plan())->json('data.id');
        $this->postJson("/api/v1/lesson-plans/plans/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        // Waiting for review: the teacher can no longer change it.
        $this->putJson("/api/v1/lesson-plans/plans/{$id}", $this->plan(['title' => 'Changed']))->assertForbidden();
        $this->postJson("/api/v1/lesson-plans/plans/{$id}/review", ['decision' => 'approve'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::LESSON_PLANS_REVIEW]);
        $this->postJson("/api/v1/lesson-plans/plans/{$id}/review", ['decision' => 'return'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/lesson-plans/plans/{$id}/review", ['decision' => 'return', 'note' => 'Add an exit ticket'])
            ->assertOk()->assertJsonPath('data.status', 'returned');
        $this->postJson("/api/v1/lesson-plans/plans/{$id}/review", ['decision' => 'approve'])->assertUnprocessable();

        $plan = LessonPlan::query()->findOrFail($id);
        $plan->forceFill(['status' => LessonPlan::SUBMITTED])->save();
        $this->postJson("/api/v1/lesson-plans/plans/{$id}/review", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_attachments_are_private(): void
    {
        Storage::fake('local');
        $this->asTeacher();
        $id = $this->postJson('/api/v1/lesson-plans/plans', $this->plan())->json('data.id');

        $fileId = $this->post("/api/v1/lesson-plans/plans/{$id}/files", ['file' => UploadedFile::fake()->create('slides.pdf', 40, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->get("/api/v1/lesson-plans/files/{$fileId}")->assertOk();

        $this->actingAsAdminWithPermissions([Permissions::CLASSES_VIEW]);
        $this->get("/api/v1/lesson-plans/files/{$fileId}")->assertForbidden();
    }

    public function test_teaching_log_progress_and_delays(): void
    {
        $this->asTeacher();
        $grade = GradeLevel::query()->create(['code' => 'G7', 'name' => 'Grade 7', 'level_order' => 7]);
        $this->myClass->update(['grade_level_id' => $grade->id]);
        $grade->subjects()->attach($this->math->id);
        $l1 = CurriculumLesson::query()->create(['grade_level_id' => $grade->id, 'book_id' => $this->math->id, 'title' => 'Fractions', 'sort_order' => 1]);
        $l2 = CurriculumLesson::query()->create(['grade_level_id' => $grade->id, 'book_id' => $this->math->id, 'title' => 'Decimals', 'sort_order' => 2]);
        CurriculumLesson::query()->create(['grade_level_id' => $grade->id, 'book_id' => $this->math->id, 'title' => 'Percent', 'sort_order' => 3]);

        $this->getJson("/api/v1/lesson-plans/curriculum-lessons?class_id={$this->myClass->id}&book_id={$this->math->id}")->assertOk()->assertJsonCount(3, 'data');

        // Two plans in the past; only the first is taught.
        $taught = $this->postJson('/api/v1/lesson-plans/plans', $this->plan(['curriculum_lesson_id' => $l1->id, 'planned_date' => now()->subDays(5)->toDateString(), 'homework_due' => null]))->json('data.id');
        $late = $this->postJson('/api/v1/lesson-plans/plans', $this->plan(['curriculum_lesson_id' => $l2->id, 'title' => 'Decimals', 'planned_date' => now()->subDays(2)->toDateString(), 'homework_due' => null]))->json('data.id');
        $this->postJson('/api/v1/lesson-plans/logs', [
            'class_id' => $this->myClass->id, 'book_id' => $this->math->id, 'curriculum_lesson_id' => $l1->id, 'lesson_plan_id' => $taught,
            'taught_on' => now()->subDays(5)->toDateString(), 'title' => 'Fractions', 'status' => 'done', 'homework' => 'Ex 1–5',
        ])->assertCreated();
        $this->postJson('/api/v1/lesson-plans/logs', ['class_id' => $this->myClass->id, 'taught_on' => now()->addDay()->toDateString(), 'title' => 'x', 'status' => 'done'])
            ->assertUnprocessable()->assertJsonValidationErrors('taught_on');

        $math = $this->getJson("/api/v1/lesson-plans/progress?class_id={$this->myClass->id}")->assertOk()->json('data.subjects.0');
        $this->assertSame([3, 1, 2, 33, 'Decimals'], [$math['total'], $math['done'], $math['remaining'], $math['percent'], $math['next']]);

        $delays = $this->getJson('/api/v1/lesson-plans/delays')->assertOk()->json('data');
        $this->assertSame([$late], array_column($delays, 'id'));
        $this->assertSame(2, $delays[0]['days_late']);
    }

    public function test_a_teacher_sees_only_their_own_plans(): void
    {
        $this->asTeacher();
        $this->postJson('/api/v1/lesson-plans/plans', $this->plan())->assertCreated();
        $other = Staff::factory()->create(['position_id' => $this->me->position_id]);
        LessonPlan::query()->create(['staff_id' => $other->id, 'class_id' => $this->otherClass->id, 'planned_date' => now()->toDateString(), 'title' => 'Not mine']);

        $this->getJson('/api/v1/lesson-plans/plans')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAsAdminWithPermissions([Permissions::LESSON_PLANS_VIEW]);
        $this->getJson('/api/v1/lesson-plans/plans')->assertOk()->assertJsonCount(2, 'data');
    }
}

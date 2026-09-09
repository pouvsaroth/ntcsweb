<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentFeedback;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class StudentFeedbackTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function studentWithUser(): array
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);

        return [$student, $user];
    }

    private function enrollWithTeacher(Student $student): Staff
    {
        $teacher = Staff::factory()->create();
        $class = SchoolClass::factory()->withTeacher($teacher)->create();
        Enrollment::factory()->forClass($class)->forStudent($student)->create();

        return $teacher;
    }

    public function test_a_student_can_submit_a_comment_about_the_school(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $this->actingAsTenantUser($user);

        $response = $this->postJson('/api/v1/my-feedback', [
            'type' => StudentFeedback::TYPE_COMMENT,
            'topic' => StudentFeedback::TOPIC_SCHOOL,
            'subject' => 'Library hours',
            'message' => 'Could the library stay open later?',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', StudentFeedback::STATUS_OPEN);
        $this->assertSame(1, StudentFeedback::where('student_id', $student->id)->count());
    }

    public function test_a_student_can_submit_a_request_about_a_teacher_who_teaches_them(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $teacher = $this->enrollWithTeacher($student);
        $this->actingAsTenantUser($user);

        $response = $this->postJson('/api/v1/my-feedback', [
            'type' => StudentFeedback::TYPE_REQUEST,
            'topic' => StudentFeedback::TOPIC_TEACHER,
            'teacher_id' => $teacher->id,
            'subject' => 'Extra homework help',
            'message' => 'Could we get extra review before the exam?',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.teacher.id', $teacher->id);
    }

    public function test_submitting_feedback_about_a_teacher_who_does_not_teach_the_student_fails(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $unrelatedTeacher = Staff::factory()->create();
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-feedback', [
            'type' => StudentFeedback::TYPE_REQUEST,
            'topic' => StudentFeedback::TOPIC_TEACHER,
            'teacher_id' => $unrelatedTeacher->id,
            'subject' => 'Extra homework help',
            'message' => 'Could we get extra review before the exam?',
        ])->assertUnprocessable();
    }

    public function test_the_teachers_endpoint_only_lists_the_students_own_teachers(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $teacher = $this->enrollWithTeacher($student);
        Staff::factory()->create(); // an unrelated teacher
        $this->actingAsTenantUser($user);

        $response = $this->getJson('/api/v1/my-feedback/teachers')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $teacher->id);
    }

    public function test_submitting_feedback_requires_a_linked_student_record(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $user = User::factory()->forTenant($this->tenant)->create();
        $this->actingAsTenantUser($user);

        $this->postJson('/api/v1/my-feedback', [
            'type' => StudentFeedback::TYPE_COMMENT,
            'topic' => StudentFeedback::TOPIC_SCHOOL,
            'subject' => 'No student record',
            'message' => 'This account has no student record',
        ])->assertForbidden();
    }

    public function test_a_student_sees_only_their_own_feedback(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        [$otherStudent] = $this->studentWithUser();

        StudentFeedback::factory()->forStudent($student)->create(['subject' => 'Mine']);
        StudentFeedback::factory()->forStudent($otherStudent)->create(['subject' => 'Not mine']);

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/my-feedback')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.subject', 'Mine');
    }

    public function test_viewing_the_admin_queue_requires_the_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/student-feedback')->assertForbidden();
    }

    public function test_an_admin_with_permission_can_reply_and_status_flips_to_replied(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::STUDENT_FEEDBACK_VIEW, Permissions::STUDENT_FEEDBACK_REPLY]);
        [$student] = $this->studentWithUser();
        $feedback = StudentFeedback::factory()->forStudent($student)->create();

        $response = $this->postJson("/api/v1/student-feedback/{$feedback->id}/reply", ['body' => 'Thanks, we will look into it.']);

        $response->assertCreated();
        $response->assertJsonPath('data.status', StudentFeedback::STATUS_REPLIED);
        $response->assertJsonCount(1, 'data.replies');
        $response->assertJsonPath('data.replies.0.user.id', $admin->id);
        $this->assertSame(StudentFeedback::STATUS_REPLIED, $feedback->fresh()->status);
    }

    public function test_replying_to_feedback_requires_the_reply_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENT_FEEDBACK_VIEW]);
        [$student] = $this->studentWithUser();
        $feedback = StudentFeedback::factory()->forStudent($student)->create();

        $this->postJson("/api/v1/student-feedback/{$feedback->id}/reply", ['body' => 'Not allowed'])->assertForbidden();
    }

    public function test_a_student_can_post_a_follow_up_message_on_their_own_thread(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $feedback = StudentFeedback::factory()->forStudent($student)->create();

        $this->actingAsTenantUser($user);
        $response = $this->postJson("/api/v1/my-feedback/{$feedback->id}/reply", ['body' => 'Any update?']);

        $response->assertCreated();
        // The student's own follow-up must not flip status to "replied" —
        // that's reserved for someone other than the student weighing in.
        $response->assertJsonPath('data.status', StudentFeedback::STATUS_OPEN);
        $response->assertJsonCount(1, 'data.replies');
    }

    public function test_a_student_cannot_reply_to_another_students_feedback(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [, $user] = $this->studentWithUser();
        [$otherStudent] = $this->studentWithUser();
        $feedback = StudentFeedback::factory()->forStudent($otherStudent)->create();

        $this->actingAsTenantUser($user);
        $this->postJson("/api/v1/my-feedback/{$feedback->id}/reply", ['body' => 'Not mine'])->assertForbidden();
    }
}

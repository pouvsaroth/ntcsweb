<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Book;
use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\ExamScore;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\Academic\ExamMention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * The student's Score card — see MyExamScoreController.
 */
class MyExamScoreTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function studentWithUser(): array
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);

        return [$student, $user];
    }

    private function scored(Student $student, string $status, ?float $score, array $attributes = []): ExamApplication
    {
        $enrollment = Enrollment::factory()->forStudent($student)->create();
        $application = ExamApplication::factory()->forStudent($student)->forEnrollment($enrollment)->create([
            'status' => $status,
            ...$attributes,
        ]);

        if ($score !== null) {
            ExamScore::query()->create(['exam_application_id' => $application->id, 'score' => $score, 'recorded_at' => now()]);
        }

        return $application;
    }

    public function test_a_student_sees_every_entered_score_newest_exam_first(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$student, $user] = $this->studentWithUser();
        $book = Book::factory()->create(['title' => 'English Book 1']);

        $older = $this->scored($student, ExamApplication::STATUS_APPROVED, 88, ['exam_date' => '2026-06-01', 'book_id' => $book->id]);
        $newer = $this->scored($student, ExamApplication::STATUS_MAKE_UP, 97.5, ['exam_date' => '2026-08-01']);

        // Not shown: no score yet, not scoreable, or someone else's.
        $this->scored($student, ExamApplication::STATUS_APPROVED, null);
        $this->scored($student, ExamApplication::STATUS_REJECTED, 99);
        [$otherStudent] = $this->studentWithUser();
        $this->scored($otherStudent, ExamApplication::STATUS_APPROVED, 90);

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/my-scores')->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.exam_application_id', $newer->id);
        $response->assertJsonPath('data.0.score', 97.5);
        $response->assertJsonPath('data.0.mention', ExamMention::EXCELLENT);
        $response->assertJsonPath('data.0.is_make_up', true);
        $response->assertJsonPath('data.1.exam_application_id', $older->id);
        $response->assertJsonPath('data.1.book', 'English Book 1');
        $response->assertJsonPath('data.1.exam_date', '2026-06-01');
        $response->assertJsonPath('data.1.mention', ExamMention::GOOD);
        $response->assertJsonPath('data.1.is_make_up', false);
    }

    public function test_it_requires_a_linked_student_record(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $user = User::factory()->forTenant($this->tenant)->create();
        Staff::factory()->withUser($user)->create();
        $this->actingAsTenantUser($user);

        $this->getJson('/api/v1/my-scores')->assertUnprocessable();
    }

    public function test_the_mention_scale_boundaries(): void
    {
        $this->assertSame(ExamMention::EXCELLENT, ExamMention::for(95.01));
        $this->assertSame(ExamMention::VERY_GOOD, ExamMention::for(95));
        $this->assertSame(ExamMention::VERY_GOOD, ExamMention::for(90.5));
        $this->assertSame(ExamMention::GOOD, ExamMention::for(90));
        $this->assertSame(ExamMention::GOOD, ExamMention::for(85));
        $this->assertSame(ExamMention::FAIL, ExamMention::for(84.99));
        $this->assertSame(ExamMention::FAIL, ExamMention::for(0));
    }
}

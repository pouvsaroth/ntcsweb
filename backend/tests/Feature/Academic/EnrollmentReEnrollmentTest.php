<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * Re-enrollment must create a NEW enrollment row, never overwrite or reuse
 * the old (dropped) one — both stay in history with their own invoice.
 */
class EnrollmentReEnrollmentTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_a_student_may_re_enroll_in_the_same_class_and_package_after_dropping(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_CANCEL]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $first = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/enrollments/{$first}/cancel", ['reason' => 'Requested a break'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dropped');

        $second = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated()->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, Enrollment::where('student_id', $student->id)->count());
        $this->assertSame(2, Invoice::where('student_id', $student->id)->count());
        $this->assertSame('active', Enrollment::findOrFail($second)->status);
        $this->assertSame('dropped', Enrollment::findOrFail($first)->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function openStatuses(): array
    {
        return [
            'active' => ['active'],
            'not started' => ['not_started'],
            'exam ready' => ['exam_ready'],
            'suspended' => ['suspended'],
            'stopped' => ['stopped'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('openStatuses')]
    public function test_a_student_cannot_be_enrolled_twice_in_a_package_they_are_still_enrolled_in(string $status): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $payload = [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ];

        $first = $this->postJson('/api/v1/enrollments/package', $payload)->assertCreated()->json('data.id');
        Enrollment::whereKey($first)->update(['status' => $status]);

        // A different class of the same course — the request's own unique
        // rule only ever caught the same class.
        $otherClass = SchoolClass::factory()->forProgram($this->computerProgram)->create(['name' => 'Computer Evening B']);

        $this->postJson('/api/v1/enrollments/package', [...$payload, 'class_id' => $otherClass->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('course_package_id');

        $this->assertSame(1, Enrollment::where('student_id', $student->id)->count());
        $this->assertSame(1, Invoice::where('student_id', $student->id)->count());
    }

    public function test_a_student_may_enroll_in_the_same_package_in_another_class_after_completing_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->create();

        $payload = [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ];

        $first = $this->postJson('/api/v1/enrollments/package', $payload)->assertCreated()->json('data.id');
        Enrollment::whereKey($first)->update(['status' => 'completed']);

        $otherClass = SchoolClass::factory()->forProgram($this->computerProgram)->create(['name' => 'Computer Evening B']);

        $this->postJson('/api/v1/enrollments/package', [...$payload, 'class_id' => $otherClass->id])->assertCreated();

        $this->assertSame(2, Enrollment::where('student_id', $student->id)->count());
    }
}

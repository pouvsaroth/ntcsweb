<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\Invoice;
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
        $student = Student::factory()->forTenant($this->tenant)->create();

        $first = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/enrollments/{$first}/cancel", ['reason' => 'Requested a break'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dropped');

        $second = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ])->assertCreated()->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, Enrollment::where('student_id', $student->id)->count());
        $this->assertSame(2, Invoice::where('student_id', $student->id)->count());
        $this->assertSame('active', Enrollment::findOrFail($second)->status);
        $this->assertSame('dropped', Enrollment::findOrFail($first)->status);
    }
}

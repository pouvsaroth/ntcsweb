<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicProgram;
use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * The spec's own invalid-combination example: a Computer student cannot be
 * enrolled using an English package, and a package not on a class's menu
 * cannot be used to enroll into that class — the server must reject these
 * even if a broken/malicious frontend sends them.
 */
class EnrollmentCrossProgramRejectionTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_a_package_from_a_different_program_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();

        $englishProgram = AcademicProgram::factory()->create(['code' => 'ENG', 'name' => 'English']);
        $englishPackage = CoursePackage::factory()->forProgram($englishProgram)->create(['code' => 'ENG101', 'price' => 40]);

        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $englishPackage->id,
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, Enrollment::count());
    }

    public function test_a_package_not_on_the_classs_menu_is_rejected_even_within_the_same_program(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();

        // A second package in the same program, but never attached to
        // computerEveningClass's menu (class_course_package).
        $excelOnlyPackage = CoursePackage::factory()->forProgram($this->computerProgram)->create(['code' => 'EXCEL2024', 'price' => 20]);
        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $excelOnlyPackage->id,
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, Enrollment::count());
    }

    public function test_a_class_with_no_program_offering_cannot_be_enrolled_into_via_package(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();

        $bareClass = SchoolClass::factory()->create(['name' => 'Unlinked class']);
        $bareClass->coursePackages()->sync([$this->msWordPackage->id]);
        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $bareClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, Enrollment::count());
    }

    public function test_program_offering_uniqueness_is_enforced_per_program_mode_and_year(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAM_OFFERINGS_CREATE]);
        $this->setUpAcademicCatalog();

        $response = $this->postJson('/api/v1/program-offerings', [
            'academic_program_id' => $this->computerProgram->id,
            'study_mode_id' => $this->partTimeMode->id,
            'academic_year_id' => $this->year2026->id,
        ]);

        $response->assertUnprocessable();
    }
}

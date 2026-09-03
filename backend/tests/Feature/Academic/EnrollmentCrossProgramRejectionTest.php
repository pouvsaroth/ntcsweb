<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicProgram;
use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\Product;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use App\Support\Billing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * The spec's own invalid-combination example: a Computer student cannot be
 * enrolled using an English package — the server must reject this even if a
 * broken/malicious frontend sends it. A class is just a schedule/room/
 * teacher, though — it never needs to "offer" a package on its own menu for
 * a same-program enrollment to be valid (see the sibling test below).
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
            'fee_type' => 'term',
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, Enrollment::count());
    }

    public function test_a_package_not_on_the_classs_menu_is_still_allowed_within_the_same_program(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();

        // A second package in the same program, deliberately never attached
        // to computerEveningClass's menu (class_course_package) — a class is
        // just a schedule/room/teacher, so this must still succeed.
        $product = Product::factory()->create(['code' => 'EXCEL2024', 'name' => 'Excel 2024', 'type' => ProductType::COURSE_FEE, 'price' => 20]);
        $excelOnlyPackage = CoursePackage::factory()->forProgram($this->computerProgram)
            ->create(['code' => 'EXCEL2024', 'price' => 20, 'fee_term' => 20, 'product_id' => $product->getKey()]);
        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $excelOnlyPackage->id,
            'fee_type' => 'term',
        ]);

        $response->assertCreated();
        $this->assertSame(1, Enrollment::count());
    }

    public function test_a_class_with_no_program_cannot_be_enrolled_into_via_package(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();

        $bareClass = SchoolClass::factory()->create(['name' => 'Unlinked class']);
        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $bareClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, Enrollment::count());
    }
}

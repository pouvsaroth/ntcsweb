<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Book;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * Proves the legacy book-billed enrollment path (EnrollmentController::store())
 * keeps working completely unmodified alongside the new package-billed path,
 * even within the very same class — the two coexist on one `enrollments`
 * table by design (see the enrollments_book_xor_package CHECK constraint).
 */
class LegacyBookEnrollmentRegressionTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_a_book_based_enrollment_still_works_on_a_class_that_also_has_a_program(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();

        $book = Book::factory()->create();
        $this->computerEveningClass->books()->attach($book->id);
        $student = Student::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson('/api/v1/enrollments', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'book_id' => $book->id,
            'enrolled_at' => '2026-01-15',
            'fee' => 15,
        ]);

        $response->assertCreated();
        $enrollment = Enrollment::firstOrFail();
        $this->assertNull($enrollment->course_package_id);
        $this->assertSame($book->id, $enrollment->book_id);
    }

    public function test_a_book_enrollment_and_a_package_enrollment_can_coexist_in_the_same_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();

        $book = Book::factory()->create();
        $this->computerEveningClass->books()->attach($book->id);
        $bookStudent = Student::factory()->forTenant($this->tenant)->create();
        $packageStudent = Student::factory()->forTenant($this->tenant)->create();

        $this->postJson('/api/v1/enrollments', [
            'student_id' => $bookStudent->id, 'class_id' => $this->computerEveningClass->id,
            'book_id' => $book->id, 'enrolled_at' => '2026-01-15', 'fee' => 15,
        ])->assertCreated();

        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $packageStudent->id, 'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ])->assertCreated();

        $this->assertSame(2, Enrollment::where('class_id', $this->computerEveningClass->id)->count());
    }
}

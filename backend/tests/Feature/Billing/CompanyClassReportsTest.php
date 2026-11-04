<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Company;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * Billing > Companies, step 3: company students (no invoices of their own)
 * are not treated as unpaid anywhere, and a class never mixes company and
 * self-paying students.
 */
class CompanyClassReportsTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    private const PERMS = [Permissions::ENROLLMENTS_CREATE, Permissions::INVOICES_VIEW, Permissions::INVOICES_CREATE, Permissions::INVOICES_CANCEL, Permissions::CLASSES_UPDATE];

    private function companyClass(): SchoolClass
    {
        return SchoolClass::factory()->forProgram($this->computerProgram)->create(['company_id' => Company::factory()->create()->id]);
    }

    private function addStudents(SchoolClass $class, int $count, string $date = '2026-08-01'): void
    {
        $this->postJson('/api/v1/enrollments/class-batch', [
            'class_id' => $class->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => $date,
            'students' => Student::factory()->count($count)->create()->map(fn ($s) => ['student_id' => $s->id])->all(),
        ])->assertCreated();
    }

    public function test_company_students_are_not_listed_as_term_students_studying_too_long(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->setUpAcademicCatalog();
        $selfPaid = Student::factory()->create();
        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $selfPaid->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'enrolled_at' => '2026-08-01',
            'fee_type' => 'term',
        ])->assertCreated();
        $this->addStudents($this->companyClass(), 2);

        $this->travelTo(Carbon::parse('2026-09-10 09:00'));
        $this->getJson('/api/v1/term-study-alerts')->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.student.id', $selfPaid->id);
    }

    public function test_who_pays_for_a_class_only_changes_while_it_has_no_students(): void
    {
        $this->actingAsAdminWithPermissions(self::PERMS);
        $this->setUpAcademicCatalog();
        $other = Company::factory()->create();

        // Empty class: free to change.
        $empty = $this->companyClass();
        $this->putJson("/api/v1/classes/{$empty->id}", ['company_id' => $other->id])->assertOk();
        $this->putJson("/api/v1/classes/{$empty->id}", ['company_id' => null])->assertOk();

        // Company students in it: cannot become self-paid or another company's.
        $class = $this->companyClass();
        $this->addStudents($class, 1);
        $this->putJson("/api/v1/classes/{$class->id}", ['company_id' => null])->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->putJson("/api/v1/classes/{$class->id}", ['company_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('company_id');
        // Saving the class without touching who pays still works.
        $this->putJson("/api/v1/classes/{$class->id}", ['name' => 'Renamed', 'company_id' => $class->company_id])->assertOk();

        // Self-paying students in it: cannot become a company class.
        $this->postJson('/api/v1/enrollments/package', [
            'student_id' => Student::factory()->create()->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term',
        ])->assertCreated();
        $this->putJson("/api/v1/classes/{$this->computerEveningClass->id}", ['company_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('company_id');
    }
}

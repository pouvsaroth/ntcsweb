<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

class EnrollmentTransferTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_transferring_an_enrollment_drops_the_old_row_and_opens_a_new_one_at_the_same_fee(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $originalId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ])->assertCreated()->json('data.id');

        $newClass = SchoolClass::factory()->withProgramOffering($this->computerPartTimeOffering)->create(['name' => 'Computer Evening B']);
        $newClass->coursePackages()->sync([$this->msWordPackage->id]);

        $response = $this->postJson("/api/v1/enrollments/{$originalId}/transfer", ['class_id' => $newClass->id]);

        $response->assertOk();
        $response->assertJsonPath('data.class.id', $newClass->id);
        $response->assertJsonPath('data.fee', 24);
        $response->assertJsonPath('data.status', 'active');

        $this->assertSame('dropped', Enrollment::findOrFail($originalId)->status);
        $this->assertSame(2, Enrollment::where('student_id', $student->id)->count());

        $log = AuditLog::where('action', AuditAction::ENROLLMENT_TRANSFERRED)->firstOrFail();
        $this->assertStringContainsString('Computer Evening B', (string) $log->description);
    }

    public function test_transferring_to_a_class_that_does_not_offer_the_package_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_TRANSFER]);
        $this->setUpAcademicCatalog();
        $student = Student::factory()->forTenant($this->tenant)->create();

        $originalId = $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ])->assertCreated()->json('data.id');

        $unrelatedClass = SchoolClass::factory()->withProgramOffering($this->computerPartTimeOffering)->create(['name' => 'No package here']);

        $response = $this->postJson("/api/v1/enrollments/{$originalId}/transfer", ['class_id' => $unrelatedClass->id]);

        $response->assertUnprocessable();
        $this->assertSame('active', Enrollment::findOrFail($originalId)->status);
        $this->assertSame(1, Enrollment::count());
    }
}

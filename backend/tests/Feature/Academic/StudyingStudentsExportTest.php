<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class StudyingStudentsExportTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /** @return array{0: SchoolClass, 1: SchoolClass} */
    private function school(): array
    {
        $morning = SchoolClass::factory()->create(['name' => 'Morning']);
        $evening = SchoolClass::factory()->create(['name' => 'Evening']);
        $add = function (string $first, string $gender, SchoolClass $class, string $status = Enrollment::STATUS_ACTIVE): Student {
            $student = Student::factory()->create(['first_name' => $first, 'last_name' => 'Test', 'gender' => $gender, 'phone' => '012 345 678']);
            Enrollment::factory()->forClass($class)->create(['student_id' => $student->id, 'status' => $status]);

            return $student;
        };
        $add('Anna', 'female', $morning);
        $add('Bora', 'male', $morning);
        $add('Chan', 'female', $evening);
        $add('Dara', 'female', $evening, Enrollment::STATUS_STOPPED); // not studying
        Student::factory()->create(['first_name' => 'Eang', 'gender' => 'female']); // in no class

        return [$morning, $evening];
    }

    public function test_only_studying_students_are_listed_with_total_and_female_count(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        $this->school();

        $data = $this->getJson('/api/v1/studying-students?columns=name,gender,class')->assertOk()->json('data');

        $this->assertSame(3, $data['total']);
        $this->assertSame(2, $data['female']);
        $this->assertSame(['name', 'gender', 'class'], $data['columns']);
        // Sorted by class, then name; only the picked columns come back.
        $this->assertSame([
            ['name' => 'Chan Test', 'gender' => 'Female', 'class' => 'Evening'],
            ['name' => 'Anna Test', 'gender' => 'Female', 'class' => 'Morning'],
            ['name' => 'Bora Test', 'gender' => 'Male', 'class' => 'Morning'],
        ], $data['rows']);
    }

    public function test_the_class_and_search_filters_narrow_the_list(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        [$morning] = $this->school();

        $data = $this->getJson("/api/v1/studying-students?class_id={$morning->id}")->assertOk()->json('data');
        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['female']);
        $this->assertSame(['code', 'name', 'gender', 'class', 'phone'], $data['columns']); // the default columns

        $this->assertSame(1, $this->getJson('/api/v1/studying-students?search=bora')->assertOk()->json('data.total'));
    }

    public function test_the_list_downloads_as_excel_and_pdf(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        $this->school();

        $excel = $this->get('/api/v1/studying-students/excel?columns=code,name,gender,address,guardian')->assertOk();
        $excel->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', (string) $excel->getContent());

        $pdf = $this->get('/api/v1/studying-students/pdf?columns=code,name,gender')->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    public function test_the_export_requires_the_students_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_VIEW]);

        $this->getJson('/api/v1/studying-students')->assertForbidden();
        $this->get('/api/v1/studying-students/excel')->assertForbidden();
        $this->get('/api/v1/studying-students/pdf')->assertForbidden();
    }
}

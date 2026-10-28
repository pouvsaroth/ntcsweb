<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\EnrollmentResponsibleStaff;
use App\Models\Staff;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/** Class > Students > Responsible Staff: one staff for the checked students, with history. */
class EnrollmentResponsibleStaffTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    /** @return list<int> */
    private function twoEnrollments(): array
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();

        return Enrollment::factory()->count(2)->sequence(fn () => [
            'student_id' => Student::factory()->create()->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ])->create()->pluck('id')->all();
    }

    /** @return array<int, array<string, mixed>> roster rows keyed by enrollment id */
    private function roster(): array
    {
        return collect($this->getJson('/api/v1/enrollments?filter[class_id]='.$this->computerEveningClass->id)->assertOk()->json('data'))
            ->keyBy('id')->all();
    }

    public function test_assigns_a_staff_to_the_checked_students_and_shows_it_on_the_roster(): void
    {
        [$a, $b] = $this->twoEnrollments();
        $staff = Staff::factory()->create(['first_name' => 'Dara', 'last_name' => 'Sok']);
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_RESPONSIBLE_STAFF, Permissions::ENROLLMENTS_VIEW]);

        $this->postJson('/api/v1/enrollment-responsible-staff', [
            'enrollment_ids' => [$a, $b], 'staff_id' => $staff->id, 'from_date' => now()->toDateString(),
        ])->assertOk()->assertJsonPath('data.saved', 2);

        $rows = $this->roster();
        $this->assertSame('Dara Sok', $rows[$a]['responsible_staff']['name']);
        $this->assertNull($rows[$a]['responsible_staff']['to_date']);
        $this->assertSame($staff->id, $rows[$b]['responsible_staff']['staff_id']);
    }

    public function test_a_new_staff_closes_the_open_period_the_day_before_and_keeps_history(): void
    {
        [$a] = $this->twoEnrollments();
        [$first, $second] = Staff::factory()->count(2)->create()->all();
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_RESPONSIBLE_STAFF, Permissions::ENROLLMENTS_VIEW]);

        $this->postJson('/api/v1/enrollment-responsible-staff', ['enrollment_ids' => [$a], 'staff_id' => $first->id, 'from_date' => now()->subDays(10)->toDateString()])->assertOk();
        $this->postJson('/api/v1/enrollment-responsible-staff', ['enrollment_ids' => [$a], 'staff_id' => $second->id, 'from_date' => now()->toDateString()])->assertOk();

        $history = EnrollmentResponsibleStaff::query()->where('enrollment_id', $a)->orderBy('from_date')->get();
        $this->assertCount(2, $history);
        $this->assertSame(now()->subDay()->toDateString(), $history[0]->to_date?->toDateString());
        $this->assertNull($history[1]->to_date);
        $this->assertSame($second->id, $this->roster()[$a]['responsible_staff']['staff_id']);

        // The same From date again replaces that period instead of adding one.
        $this->postJson('/api/v1/enrollment-responsible-staff', ['enrollment_ids' => [$a], 'staff_id' => $first->id, 'from_date' => now()->toDateString()])->assertOk();
        $this->assertSame(2, EnrollmentResponsibleStaff::query()->where('enrollment_id', $a)->count());
        $this->assertSame($first->id, $this->roster()[$a]['responsible_staff']['staff_id']);
    }

    public function test_a_period_that_ended_or_has_not_started_is_not_shown_as_current(): void
    {
        [$a, $b] = $this->twoEnrollments();
        $staff = Staff::factory()->create();
        EnrollmentResponsibleStaff::query()->create(['enrollment_id' => $a, 'staff_id' => $staff->id, 'from_date' => now()->subDays(9), 'to_date' => now()->subDays(2)]);
        EnrollmentResponsibleStaff::query()->create(['enrollment_id' => $b, 'staff_id' => $staff->id, 'from_date' => now()->addDays(3)]);
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_VIEW]);

        $rows = $this->roster();
        $this->assertNull($rows[$a]['responsible_staff']);
        $this->assertNull($rows[$b]['responsible_staff']);
    }

    public function test_excel_export_has_the_responsible_staff_column(): void
    {
        [$a] = $this->twoEnrollments();
        $staff = Staff::factory()->create(['first_name' => 'Dara', 'last_name' => 'Sok']);
        EnrollmentResponsibleStaff::query()->create(['enrollment_id' => $a, 'staff_id' => $staff->id, 'from_date' => now()->toDateString()]);
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_VIEW]);

        $response = $this->get('/api/v1/enrollment-export?'.http_build_query(['ids' => (string) $a, 'labels' => ['responsible_staff' => 'Staff in charge']]))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->getContent());
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        @unlink($path);

        $this->assertSame('Staff in charge', $rows[1][5]);
        $this->assertSame('Dara Sok', $rows[2][5]);
    }

    public function test_needs_the_permission_valid_dates_and_real_enrollments(): void
    {
        [$a] = $this->twoEnrollments();
        $staff = Staff::factory()->create();
        $body = ['enrollment_ids' => [$a], 'staff_id' => $staff->id, 'from_date' => '2026-10-28'];

        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_VIEW]);
        $this->postJson('/api/v1/enrollment-responsible-staff', $body)->assertForbidden();
        $this->getJson('/api/v1/enrollment-responsible-staff/staff')->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_RESPONSIBLE_STAFF]);
        $this->getJson('/api/v1/enrollment-responsible-staff/staff')->assertOk()->assertJsonFragment(['id' => $staff->id]);
        $this->postJson('/api/v1/enrollment-responsible-staff', [...$body, 'to_date' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('to_date');
        $this->postJson('/api/v1/enrollment-responsible-staff', [...$body, 'enrollment_ids' => [999999]])->assertUnprocessable();
        $this->assertSame(0, EnrollmentResponsibleStaff::query()->count());
    }
}

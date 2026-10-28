<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/** Class > Students > Note: one editable note per student, saved for the class at once. */
class EnrollmentNoteTest extends TestCase
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

    public function test_notes_are_saved_for_many_students_and_shown_on_the_roster(): void
    {
        [$a, $b] = $this->twoEnrollments();
        Enrollment::query()->whereKey($b)->update(['note' => 'old note']);
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_NOTE, Permissions::ENROLLMENTS_VIEW]);

        $this->putJson('/api/v1/enrollment-notes', ['notes' => [
            ['enrollment_id' => $a, 'note' => '  Needs help with typing  '],
            ['enrollment_id' => $b, 'note' => ''],
        ]])->assertOk()->assertJsonPath('data.saved', 2);

        $this->assertSame('Needs help with typing', Enrollment::query()->find($a)->note);
        $this->assertNull(Enrollment::query()->find($b)->note);
        $this->getJson("/api/v1/enrollments/{$a}")->assertOk()->assertJsonPath('data.note', 'Needs help with typing');
    }

    public function test_excel_export_has_only_the_checked_students_in_screen_order(): void
    {
        [$a, $b] = $this->twoEnrollments();
        $third = Enrollment::factory()->create([
            'student_id' => Student::factory()->create()->id,
            'class_id' => $this->computerEveningClass->id,
            'course_package_id' => $this->msWordPackage->id,
        ]);
        Enrollment::query()->whereKey($a)->update(['note' => 'Needs help']);
        Student::query()->whereKey(Enrollment::query()->find($b)->student_id)->update(['gender' => 'female']);
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_VIEW]);

        $response = $this->get('/api/v1/enrollment-export?'.http_build_query([
            'ids' => "{$b},{$a}",
            'labels' => ['name' => 'ឈ្មោះ', 'female' => 'ស្រី'],
        ]))->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->getContent());
        $reader = new \OpenSpout\Reader\XLSX\Reader;
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

        // The reader skips the blank row under the title: title, header, 2 students.
        $this->assertCount(4, $rows);
        $this->assertSame('ឈ្មោះ', $rows[1][2]);
        $this->assertSame([1, 'ស្រី'], [$rows[2][0], $rows[2][3]]);
        $this->assertSame([2, 'Needs help'], [$rows[3][0], $rows[3][6]]);
        $this->assertSame($this->msWordPackage->name, $rows[3][4]);
        $this->assertNotContains($third->student->fullName(), array_column($rows, 2));
    }

    public function test_excel_export_needs_view_permission(): void
    {
        [$a] = $this->twoEnrollments();
        $this->actingAsAdminWithPermissions([]);
        $this->getJson("/api/v1/enrollment-export?ids={$a}")->assertForbidden();
    }

    public function test_needs_the_note_permission_and_real_enrollments(): void
    {
        [$a] = $this->twoEnrollments();

        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_VIEW]);
        $this->putJson('/api/v1/enrollment-notes', ['notes' => [['enrollment_id' => $a, 'note' => 'x']]])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_NOTE]);
        $this->putJson('/api/v1/enrollment-notes', ['notes' => [['enrollment_id' => 999999, 'note' => 'x']]])->assertUnprocessable();
        $this->assertNull(Enrollment::query()->find($a)->note);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** HRM > Staff > Education (docs/hrm/staff-education.md): many schools per staff member. */
class StaffEducationTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_a_staff_member_gets_many_education_rows_newest_first(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW, Permissions::STAFF_UPDATE]);
        $staff = Staff::factory()->create();
        $url = "/api/v1/staff/{$staff->id}/educations";

        $this->postJson($url, ['level' => 'bachelor', 'institution' => 'Royal University of Phnom Penh', 'major' => 'Mathematics', 'start_year' => 2010, 'end_year' => 2014])
            ->assertCreated()->assertJsonPath('data.major', 'Mathematics');
        $master = $this->postJson($url, ['level' => 'master', 'institution' => 'NUM', 'start_year' => 2016])->assertCreated()->json('data');
        $this->postJson($url, ['level' => 'high_school', 'institution' => 'Sisowath High School', 'end_year' => 2009, 'country' => '  Cambodia '])
            ->assertCreated()->assertJsonPath('data.country', 'Cambodia');

        // Still studying first, then by end year.
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.level', 'master')->assertJsonPath('data.1.level', 'bachelor')->assertJsonPath('data.2.level', 'high_school');

        $this->putJson("{$url}/{$master['id']}", [...$master, 'end_year' => 2018])->assertOk()->assertJsonPath('data.end_year', 2018);
        $this->postJson($url, ['level' => 'phd', 'institution' => ''])->assertUnprocessable()->assertJsonValidationErrors(['level', 'institution']);
        $this->postJson($url, ['level' => 'other', 'institution' => 'X', 'start_year' => 2015, 'end_year' => 2012])->assertUnprocessable()->assertJsonValidationErrors('end_year');

        // A row belongs to its own staff member only.
        $other = Staff::factory()->create();
        $this->deleteJson("/api/v1/staff/{$other->id}/educations/{$master['id']}")->assertNotFound();
        $this->deleteJson("{$url}/{$master['id']}")->assertOk();
        $this->getJson($url)->assertJsonCount(2, 'data');
    }

    public function test_reading_needs_staff_view_and_changing_needs_staff_update(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW]);
        $staff = Staff::factory()->create();
        $url = "/api/v1/staff/{$staff->id}/educations";

        $this->getJson($url)->assertOk();
        $this->postJson($url, ['level' => 'bachelor', 'institution' => 'RUPP'])->assertForbidden();

        $this->actingAsAdminWithPermissions([]);
        $this->getJson($url)->assertForbidden();
    }
}

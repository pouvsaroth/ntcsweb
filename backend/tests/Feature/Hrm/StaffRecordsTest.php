<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** The list-style tabs of the staff form (docs/hrm/staff-profile-tabs.md). */
class StaffRecordsTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_emergency_contacts_and_family_members_are_lists_per_staff_member(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW, Permissions::STAFF_UPDATE]);
        $staff = Staff::factory()->create();
        $contacts = "/api/v1/staff/{$staff->id}/records/emergency-contacts";
        $family = "/api/v1/staff/{$staff->id}/records/family-members";

        $mother = $this->postJson($contacts, ['name' => 'Sok Dara', 'relationship' => 'mother', 'phone' => '012 345 678', 'address' => '  '])
            ->assertCreated()->assertJsonPath('data.address', null)->json('data');
        $this->postJson($contacts, ['name' => 'Chan Vuthy', 'relationship' => 'friend', 'phone' => '098 765 432'])->assertCreated();
        $this->postJson($contacts, ['name' => 'X', 'relationship' => 'boss'])->assertUnprocessable()->assertJsonValidationErrors(['relationship', 'phone']);
        $this->getJson($contacts)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Sok Dara');
        $this->putJson("{$contacts}/{$mother['id']}", [...$mother, 'alt_phone' => '011 111 111'])->assertOk()->assertJsonPath('data.alt_phone', '011 111 111');

        $this->postJson($family, ['name' => 'Sok Lina', 'relationship' => 'child', 'gender' => 'female', 'date_of_birth' => '2015-06-01'])
            ->assertCreated()->assertJsonPath('data.date_of_birth', '2015-06-01');
        $this->postJson($family, ['name' => 'Future', 'relationship' => 'child', 'date_of_birth' => now()->addYear()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('date_of_birth');
        $this->getJson($family)->assertOk()->assertJsonCount(1, 'data');

        // Rows stay with their own staff member; an unknown kind is not a route.
        $other = Staff::factory()->create();
        $this->deleteJson("/api/v1/staff/{$other->id}/records/emergency-contacts/{$mother['id']}")->assertNotFound();
        $this->getJson("/api/v1/staff/{$staff->id}/records/pets")->assertNotFound();
        $this->deleteJson("{$contacts}/{$mother['id']}")->assertOk();
        $this->getJson($contacts)->assertJsonCount(1, 'data');
    }

    public function test_languages_job_experience_and_other_skills(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW, Permissions::STAFF_UPDATE]);
        $staff = Staff::factory()->create();
        $base = "/api/v1/staff/{$staff->id}/records";

        $this->postJson("{$base}/languages", ['language' => 'English', 'level' => 'fluent', 'certificate' => 'IELTS 7.0'])
            ->assertCreated()->assertJsonPath('data.certificate', 'IELTS 7.0');
        $this->postJson("{$base}/languages", ['language' => 'French', 'level' => 'perfect'])
            ->assertUnprocessable()->assertJsonValidationErrors('level');

        // Latest job first; an empty end date means still working there.
        $this->postJson("{$base}/job-experiences", ['company' => 'ABC School', 'position' => 'Teacher', 'start_date' => '2015-01-05', 'end_date' => '2019-12-31'])->assertCreated();
        $this->postJson("{$base}/job-experiences", ['company' => 'XYZ Co', 'position' => 'Manager', 'start_date' => '2020-02-01'])
            ->assertCreated()->assertJsonPath('data.end_date', null);
        $this->postJson("{$base}/job-experiences", ['company' => 'A', 'position' => 'B', 'start_date' => '2020-02-01', 'end_date' => '2019-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        // No start date: any end date is fine.
        $this->postJson("{$base}/job-experiences", ['company' => 'Old Shop', 'position' => 'Cashier', 'end_date' => '2010-01-01'])->assertCreated();
        $this->postJson("{$base}/job-experiences", ['company' => 'A'])->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->getJson("{$base}/job-experiences")->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.company', 'XYZ Co')->assertJsonPath('data.1.company', 'ABC School')->assertJsonPath('data.2.company', 'Old Shop');

        $this->postJson("{$base}/other-skills", ['skill' => 'First aid'])->assertCreated()->assertJsonPath('data.level', null);
        $this->postJson("{$base}/other-skills", ['skill' => 'Driving', 'level' => 'expert'])->assertCreated();
        $this->getJson("{$base}/other-skills")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_reading_needs_staff_view_and_changing_needs_staff_update(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW]);
        $staff = Staff::factory()->create();
        $url = "/api/v1/staff/{$staff->id}/records/family-members";

        $this->getJson($url)->assertOk();
        $this->postJson($url, ['name' => 'A', 'relationship' => 'spouse'])->assertForbidden();

        $this->actingAsAdminWithPermissions([]);
        $this->getJson($url)->assertForbidden();
    }
}

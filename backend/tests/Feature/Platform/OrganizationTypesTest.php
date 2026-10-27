<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Models\OrganizationType;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Organization types: School, Company, Organization, Ministry… picked when a
 * customer is created; its word replaces "school" on the admin screens.
 */
class OrganizationTypesTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SchoolDatabase::class, new FakeSchoolDatabase);
        Notification::fake();
    }

    private function type(string $code): OrganizationType
    {
        return OrganizationType::query()->where('code', $code)->firstOrFail();
    }

    public function test_the_four_types_are_there_and_staff_can_add_edit_and_remove_one(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $codes = collect($this->getJson('/api/v1/platform/organization-types')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['school', 'company', 'organization', 'ministry'], $codes);

        $id = $this->postJson('/api/v1/platform/organization-types', [
            'code' => 'ngo', 'words' => ['en' => 'NGO', 'km' => 'អង្គការ'],
        ])->assertCreated()->assertJsonPath('data.words.en_plural', 'NGOs')->json('data.id');

        $this->patchJson("/api/v1/platform/organization-types/{$id}", ['words' => ['en' => 'Association', 'en_plural' => 'Associations']])
            ->assertOk()->assertJsonPath('data.words.en', 'Association');

        $this->deleteJson("/api/v1/platform/organization-types/{$id}")->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('bo_organization_types', ['id' => $id]);

        // School is always there.
        $this->deleteJson('/api/v1/platform/organization-types/'.$this->type('school')->id)->assertStatus(409);
    }

    public function test_a_type_a_customer_uses_is_turned_off_instead_of_deleted(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $company = $this->type('company');
        Tenant::factory()->create(['organization_type_id' => $company->id]);

        $this->deleteJson("/api/v1/platform/organization-types/{$company->id}")->assertOk()->assertJsonPath('data.deleted', false);
        $this->assertSame(OrganizationType::STATUS_INACTIVE, $company->fresh()->status);
    }

    public function test_a_new_customer_gets_its_type_and_staff_can_change_it(): void
    {
        $this->actingAsStaff($this->platformStaff());
        $company = $this->type('company');

        $id = $this->postJson('/api/v1/platform/schools', [
            'name' => 'Angkor Co', 'slug' => 'angkorco', 'owner_name' => 'Sok Dara', 'owner_email' => 'dara@angkor.example',
            'organization_type_id' => $company->id,
        ])->assertCreated()->assertJsonPath('data.organization_type_id', $company->id)->json('data.id');

        $ministry = $this->type('ministry');
        $this->patchJson("/api/v1/platform/schools/{$id}", ['organization_type_id' => $ministry->id])
            ->assertOk()->assertJsonPath('data.organization_type_id', $ministry->id);

        // A type that is turned off cannot be picked.
        $ministry->update(['status' => OrganizationType::STATUS_INACTIVE]);
        $this->patchJson("/api/v1/platform/schools/{$id}", ['organization_type_id' => $ministry->id])->assertUnprocessable();
    }

    public function test_sign_up_lists_the_types_and_keeps_the_one_picked(): void
    {
        RateLimiter::clear('signups:127.0.0.1');
        config(['platform.website_school' => 'newtech']);
        Tenant::factory()->create(['slug' => 'newtech']);
        $company = $this->type('company');

        $this->withHeader('X-Tenant', 'newtech')->getJson('/api/v1/public/signup/organization-types')
            ->assertOk()->assertJsonPath('data.1.code', 'company');

        $this->withHeader('X-Tenant', 'newtech')->postJson('/api/v1/public/signup', [
            'plan' => 'trial', 'school_name' => 'Angkor Co', 'slug' => 'angkorco', 'owner_name' => 'Sok Dara',
            'owner_email' => 'dara@example.com', 'owner_phone' => '012 345 678',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1', 'organization_type_id' => $company->id,
        ])->assertCreated();

        $this->assertSame($company->id, Tenant::query()->where('slug', 'angkorco')->value('organization_type_id'));
    }

    public function test_me_gives_the_admin_screens_the_customers_word(): void
    {
        $company = $this->type('company');
        $tenant = Tenant::factory()->create(['organization_type_id' => $company->id]);
        $this->actingAsTenantUser(User::factory()->forTenant($tenant)->create());
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('meta.tenant.organization.code', 'company')
            ->assertJsonPath('meta.tenant.organization.words.en', 'Company')
            ->assertJsonPath('meta.tenant.organization.words.km', 'ក្រុមហ៊ុន');

        // No type = School.
        $school = Tenant::factory()->create();
        $this->actingAsTenantUser(User::factory()->forTenant($school)->create());
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('meta.tenant.organization.code', 'school');
    }
}

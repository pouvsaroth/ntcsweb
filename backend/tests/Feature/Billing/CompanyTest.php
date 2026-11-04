<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Company;
use App\Models\SchoolClass;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Billing > Companies, and a class's "Company (paid by)" field. */
class CompanyTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::COMPANIES_VIEW, Permissions::COMPANIES_CREATE, Permissions::COMPANIES_UPDATE, Permissions::COMPANIES_DELETE];

    public function test_companies_can_be_added_listed_and_updated(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $id = $this->postJson('/api/v1/companies', [
            'name' => 'ABC Company',
            'contact_person' => 'Sok Dara',
            'phone' => '012345678',
            'tax_number' => 'K001-123456789',
        ])->assertCreated()->assertJsonPath('data.tax_number', 'K001-123456789')->json('data.id');

        $this->getJson('/api/v1/companies?search=ABC')->assertOk()->assertJsonPath('data.0.name', 'ABC Company');

        $this->putJson("/api/v1/companies/{$id}", ['name' => 'ABC Co., Ltd', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'ABC Co., Ltd')->assertJsonPath('data.is_active', false);
    }

    public function test_a_company_with_classes_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $used = Company::factory()->create();
        SchoolClass::factory()->create(['company_id' => $used->id]);
        $unused = Company::factory()->create();

        $this->deleteJson("/api/v1/companies/{$used->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/companies/{$unused->id}")->assertNoContent();
        $this->assertDatabaseHas('companies', ['id' => $used->id], 'tenant');
        $this->assertDatabaseMissing('companies', ['id' => $unused->id], 'tenant');
    }

    public function test_companies_need_their_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_VIEW]);

        $this->getJson('/api/v1/companies')->assertForbidden();
        $this->postJson('/api/v1/companies', ['name' => 'X'])->assertForbidden();
    }

    public function test_a_class_can_be_set_to_be_paid_by_a_company(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_VIEW, Permissions::CLASSES_CREATE, Permissions::CLASSES_UPDATE]);
        $company = Company::factory()->create(['name' => 'ABC Company']);

        $id = $this->postJson('/api/v1/classes', ['name' => 'Class A', 'company_id' => $company->id])
            ->assertCreated()->assertJsonPath('data.company.name', 'ABC Company')->json('data.id');

        $this->getJson("/api/v1/classes?filter[company_id]={$company->id}")->assertOk()->assertJsonPath('data.0.id', $id);
        SchoolClass::factory()->create(['name' => 'Self-paid class']);
        $this->getJson('/api/v1/classes?filter[has_company]=true')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        $this->putJson("/api/v1/classes/{$id}", ['company_id' => null])->assertOk()->assertJsonPath('data.company_id', null);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Loan;

use App\Models\LoanCustomer;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolSubscription;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class LoanCustomerTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [
        Permissions::LOAN_CUSTOMERS_VIEW, Permissions::LOAN_CUSTOMERS_CREATE,
        Permissions::LOAN_CUSTOMERS_UPDATE, Permissions::LOAN_CUSTOMERS_DELETE,
    ];

    public function test_a_customer_is_added_with_a_code_then_updated_and_listed(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $id = $this->postJson('/api/v1/loan-customers', [
            'name' => 'Sok Dara', 'gender' => 'female', 'phone' => '012 345 678', 'id_card_no' => '010203040',
            'date_of_birth' => '1990-05-01', 'address' => 'Phnom Penh',
        ])->assertCreated()->assertJsonPath('data.code', fn ($code) => (bool) preg_match('/^C\d{5}$/', (string) $code))->json('data.id');

        $this->putJson("/api/v1/loan-customers/{$id}", ['phone' => '099 111 222'])->assertOk()->assertJsonPath('data.phone', '099 111 222');

        $this->getJson('/api/v1/loan-customers?search=dara')->assertOk()->assertJsonPath('data.0.name', 'Sok Dara');
        $this->getJson('/api/v1/loan-customers?search=nobody')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_name_is_required_and_gender_must_be_known(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->postJson('/api/v1/loan-customers', ['gender' => 'x'])->assertJsonValidationErrors(['name', 'gender']);
    }

    public function test_a_customer_can_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $customer = LoanCustomer::factory()->create();

        $this->deleteJson("/api/v1/loan-customers/{$customer->id}")->assertNoContent();
        $this->assertNull(LoanCustomer::query()->find($customer->id));
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LOAN_CUSTOMERS_VIEW]);
        $customer = LoanCustomer::factory()->create();

        $this->getJson('/api/v1/loan-customers')->assertOk();
        $this->postJson('/api/v1/loan-customers', ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/loan-customers/{$customer->id}", ['name' => 'Y'])->assertForbidden();
        $this->deleteJson("/api/v1/loan-customers/{$customer->id}")->assertForbidden();
    }

    public function test_customers_and_borrowings_belong_to_the_loan_module(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::LOAN_CUSTOMERS_VIEW, Permissions::BORROWINGS_VIEW]);
        $plan = Plan::query()->create([
            'code' => 'p-loan', 'name' => 'Plan', 'price' => 10, 'currency' => 'USD',
            'period_months' => 1, 'modules' => ['academics', 'accounting'], 'menus' => null, 'status' => Plan::STATUS_ACTIVE,
        ]);
        SchoolSubscription::query()->where('tenant_id', $this->tenant->id)->live()->update(['status' => SchoolSubscription::REPLACED]);
        SchoolSubscription::query()->create([
            'tenant_id' => $this->tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'start_on' => now()->toDateString(), 'end_on' => now()->addMonth()->toDateString(),
        ]);

        // Accounting alone no longer brings Borrowings.
        $this->getJson('/api/v1/loan-customers')->assertForbidden();
        $this->getJson('/api/v1/lenders')->assertForbidden();

        $plan->update(['modules' => ['academics', 'accounting', 'loan']]);

        $this->getJson('/api/v1/loan-customers')->assertOk();
        $this->getJson('/api/v1/lenders')->assertOk();
    }
}

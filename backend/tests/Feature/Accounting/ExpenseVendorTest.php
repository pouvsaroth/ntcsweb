<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Expense;
use App\Models\Supplier;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Expense vendors come from the shared Suppliers list (also used by Assets). */
class ExpenseVendorTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    public function test_an_expense_user_can_list_and_add_vendors_without_asset_permissions(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_VIEW, Permissions::EXPENSE_CREATE, Permissions::SUPPLIERS_CREATE]);

        $this->postJson('/api/v1/suppliers', ['name' => 'EDC'])->assertCreated();
        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonPath('data.0.name', 'EDC');
    }

    public function test_a_user_without_expense_or_asset_permissions_cannot_see_vendors(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_VIEW]);

        $this->getJson('/api/v1/suppliers')->assertForbidden();
    }

    public function test_an_expense_keeps_the_picked_vendor_and_its_name(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE, Permissions::EXPENSE_VIEW]);
        $this->setUpChartOfAccounts();
        $supplier = Supplier::factory()->create(['name' => 'EDC']);

        $response = $this->postJson('/api/v1/expenses', [
            'account_id' => $this->electricityAccount->id, 'expense_entity_id' => $this->expenseEntity->id,
            'amount' => 30,
            'supplier_id' => $supplier->id,
            'vendor' => 'typed name ignored',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.supplier_id', $supplier->id);
        $response->assertJsonPath('data.vendor', 'EDC');

        $this->getJson("/api/v1/expenses?filter[supplier_id]={$supplier->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_vendor_used_by_an_expense_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE, Permissions::EXPENSE_UPDATE, Permissions::SUPPLIERS_DELETE]);
        $this->setUpChartOfAccounts();
        $supplier = Supplier::factory()->create();
        Expense::factory()->create(['account_id' => $this->electricityAccount->id, 'expense_entity_id' => $this->expenseEntity->id, 'supplier_id' => $supplier->id]);

        $this->deleteJson("/api/v1/suppliers/{$supplier->id}")->assertUnprocessable();
    }
}

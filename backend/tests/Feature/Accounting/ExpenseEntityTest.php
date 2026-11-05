<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Expense;
use App\Models\ExpenseEntity;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Accounting > Expense for: who an expense is for (Newtech, Flexi, Family...). */
class ExpenseEntityTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    public function test_an_expense_user_can_add_and_list_expense_for_entries(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_VIEW, Permissions::EXPENSE_CREATE]);

        $this->postJson('/api/v1/expense-entities', ['name' => 'Flexi'])->assertCreated()->assertJsonPath('data.is_active', true);
        $this->postJson('/api/v1/expense-entities', ['name' => ''])->assertUnprocessable();

        $this->getJson('/api/v1/expense-entities')->assertOk()->assertJsonPath('data.0.name', 'Flexi');
    }

    public function test_without_expense_permissions_the_list_is_forbidden(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INVOICES_VIEW]);

        $this->getJson('/api/v1/expense-entities')->assertForbidden();
    }

    public function test_a_new_expense_must_say_who_it_is_for(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE, Permissions::EXPENSE_VIEW]);
        $this->setUpChartOfAccounts();

        $this->postJson('/api/v1/expenses', ['account_id' => $this->electricityAccount->id, 'amount' => 30])
            ->assertUnprocessable()->assertJsonValidationErrors('expense_entity_id');

        $inactive = ExpenseEntity::factory()->create(['is_active' => false]);
        $this->postJson('/api/v1/expenses', ['account_id' => $this->electricityAccount->id, 'amount' => 30, 'expense_entity_id' => $inactive->id])
            ->assertUnprocessable()->assertJsonValidationErrors('expense_entity_id');

        $family = ExpenseEntity::factory()->create(['name' => 'Family']);
        $this->postJson('/api/v1/expenses', ['account_id' => $this->electricityAccount->id, 'amount' => 30, 'expense_entity_id' => $family->id])
            ->assertCreated()->assertJsonPath('data.expense_entity.name', 'Family');

        $this->getJson("/api/v1/expenses?filter[expense_entity_id]={$family->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/expenses?filter[expense_entity_id]={$this->expenseEntity->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_entry_used_by_an_expense_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE, Permissions::EXPENSE_UPDATE]);
        $this->setUpChartOfAccounts();
        Expense::factory()->create(['account_id' => $this->electricityAccount->id, 'expense_entity_id' => $this->expenseEntity->id]);
        $unused = ExpenseEntity::factory()->create();

        $this->deleteJson("/api/v1/expense-entities/{$this->expenseEntity->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/expense-entities/{$unused->id}")->assertNoContent();
    }
}

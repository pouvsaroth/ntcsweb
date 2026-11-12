<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\ExpenseSupport;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Accounting > Expenses > Support: money or food people gave towards an Expense for. */
class ExpenseSupportTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private function asManager(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::EXPENSE_SUPPORTS_VIEW, Permissions::EXPENSE_SUPPORTS_CREATE,
            Permissions::EXPENSE_SUPPORTS_UPDATE, Permissions::EXPENSE_SUPPORTS_DELETE,
        ]);
        $this->setUpChartOfAccounts();
    }

    public function test_money_and_food_support_are_recorded_and_listed(): void
    {
        $this->asManager();

        $this->postJson('/api/v1/expense-supports', [
            'name' => 'Aunt Sophea', 'phone' => '012 345 678', 'relationship' => 'relative', 'support_date' => '2026-11-12',
            'kind' => 'money', 'amount' => 100, 'currency' => 'USD', 'expense_entity_id' => $this->expenseEntity->id, 'note' => 'Came with uncle',
        ])->assertCreated()->assertJsonPath('data.amount', 100)->assertJsonPath('data.expense_entity.id', $this->expenseEntity->id);

        // Food: what was brought; no amount, so no currency is kept.
        $this->postJson('/api/v1/expense-supports', [
            'name' => 'Neighbour Dara', 'support_date' => '2026-11-12', 'kind' => 'food',
            'items' => 'Rice soup and fruit', 'currency' => 'USD', 'expense_entity_id' => $this->expenseEntity->id,
        ])->assertCreated()->assertJsonPath('data.amount', null)->assertJsonPath('data.currency', null);

        $this->getJson('/api/v1/expense-supports')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/expense-supports?filter[kind]=food')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.items', 'Rice soup and fruit');
        $this->getJson('/api/v1/expense-supports?search=Sophea')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/expense-supports?from=2026-11-13')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_money_needs_an_amount_and_food_needs_what_was_brought(): void
    {
        $this->asManager();
        $base = ['name' => 'X', 'support_date' => '2026-11-12', 'expense_entity_id' => $this->expenseEntity->id];

        $this->postJson('/api/v1/expense-supports', [...$base, 'kind' => 'money'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount', 'currency']);
        $this->postJson('/api/v1/expense-supports', [...$base, 'kind' => 'food'])
            ->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->postJson('/api/v1/expense-supports', [...$base, 'kind' => 'food', 'items' => 'Bread', 'amount' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['currency']);
        $this->postJson('/api/v1/expense-supports', ['kind' => 'gift'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'support_date', 'kind', 'expense_entity_id']);
    }

    public function test_it_can_be_edited_and_deleted(): void
    {
        $this->asManager();
        $support = ExpenseSupport::query()->create([
            'name' => 'Friend', 'support_date' => '2026-11-12', 'kind' => 'money', 'amount' => 20, 'currency' => 'USD', 'expense_entity_id' => $this->expenseEntity->id,
        ]);

        $this->putJson("/api/v1/expense-supports/{$support->id}", [
            'name' => 'Friend Vanna', 'support_date' => '2026-11-11', 'kind' => 'money', 'amount' => 80000, 'currency' => 'KHR', 'expense_entity_id' => $this->expenseEntity->id,
        ])->assertOk()->assertJsonPath('data.name', 'Friend Vanna')->assertJsonPath('data.currency', 'KHR');

        $this->deleteJson("/api/v1/expense-supports/{$support->id}")->assertNoContent();
        $this->assertNull(ExpenseSupport::query()->find($support->id));
    }

    public function test_an_expense_for_with_support_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_FOR_DELETE]);
        $this->setUpChartOfAccounts();
        ExpenseSupport::query()->create([
            'name' => 'Friend', 'support_date' => '2026-11-12', 'kind' => 'food', 'items' => 'Fruit', 'expense_entity_id' => $this->expenseEntity->id,
        ]);

        $this->deleteJson("/api/v1/expense-entities/{$this->expenseEntity->id}")->assertUnprocessable();
    }

    public function test_each_action_needs_its_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_SUPPORTS_VIEW]);
        $this->setUpChartOfAccounts();
        $support = ExpenseSupport::query()->create([
            'name' => 'Friend', 'support_date' => '2026-11-12', 'kind' => 'food', 'items' => 'Fruit', 'expense_entity_id' => $this->expenseEntity->id,
        ]);
        $body = ['name' => 'Y', 'support_date' => '2026-11-12', 'kind' => 'food', 'items' => 'Bread', 'expense_entity_id' => $this->expenseEntity->id];

        $this->getJson('/api/v1/expense-supports')->assertOk();
        $this->postJson('/api/v1/expense-supports', $body)->assertForbidden();
        $this->putJson("/api/v1/expense-supports/{$support->id}", $body)->assertForbidden();
        $this->deleteJson("/api/v1/expense-supports/{$support->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_VIEW]);
        $this->getJson('/api/v1/expense-supports')->assertForbidden();
    }
}

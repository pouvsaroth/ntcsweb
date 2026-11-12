<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\Accounting\AccountType;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Expenses > Add many: several expenses on one date in one save. */
class ExpenseBatchTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    public function test_each_line_becomes_an_expense_with_the_shared_fields(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE, Permissions::EXPENSE_VIEW]);
        $this->setUpChartOfAccounts();
        $water = Account::factory()->type(AccountType::EXPENSE)->create(['code' => '5310', 'name' => 'Water']);
        $supplier = Supplier::factory()->create(['name' => 'City Market']);

        $this->postJson('/api/v1/expenses/batch', [
            'expense_date' => '2026-11-12',
            'expense_entity_id' => $this->expenseEntity->id,
            'currency' => 'KHR',
            'payment_method' => 'CASH',
            'supplier_id' => $supplier->id,
            'lines' => [
                ['account_id' => $this->electricityAccount->id, 'amount' => 120000, 'description' => 'Electric bill'],
                ['account_id' => $water->id, 'amount' => 40000, 'description' => 'Water bill', 'reference_number' => 'W-11'],
                ['account_id' => $water->id, 'amount' => 5000],
            ],
        ])->assertCreated()->assertJsonCount(3, 'data');

        $expenses = Expense::query()->orderBy('id')->get();
        $this->assertCount(3, $expenses);
        foreach ($expenses as $expense) {
            $this->assertSame('2026-11-12', $expense->expense_date->toDateString());
            $this->assertSame(['KHR', 'CASH', $supplier->id, 'City Market', $this->expenseEntity->id],
                [$expense->currency, $expense->payment_method, $expense->supplier_id, $expense->vendor, $expense->expense_entity_id]);
        }
        $this->assertSame([120000.0, 40000.0, 5000.0], $expenses->map(fn ($e) => (float) $e->amount)->all());
        $this->assertSame(['Electric bill', 'Water bill', null], $expenses->pluck('description')->all());
        $this->assertSame('W-11', $expenses[1]->reference_number);
        $this->assertCount(3, $expenses->pluck('expense_number')->unique());
    }

    public function test_one_bad_line_saves_none_and_names_the_line(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE, Permissions::EXPENSE_VIEW]);
        $this->setUpChartOfAccounts();

        $this->postJson('/api/v1/expenses/batch', [
            'expense_date' => '2026-11-12',
            'expense_entity_id' => $this->expenseEntity->id,
            'lines' => [
                ['account_id' => $this->electricityAccount->id, 'amount' => 10],
                ['account_id' => $this->electricityAccount->id, 'amount' => ''],
                ['account_id' => $this->cashAccount->id, 'amount' => 5], // not an expense account
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines.1.amount', 'lines.2.account_id']);

        $this->assertSame(0, Expense::query()->count());
    }

    public function test_the_date_expense_for_and_at_least_one_line_are_required(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE]);
        $this->setUpChartOfAccounts();

        $this->postJson('/api/v1/expenses/batch', ['lines' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['expense_date', 'expense_entity_id', 'lines']);
    }

    public function test_it_needs_the_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_VIEW]);
        $this->setUpChartOfAccounts();

        $this->postJson('/api/v1/expenses/batch', [
            'expense_date' => '2026-11-12', 'expense_entity_id' => $this->expenseEntity->id,
            'lines' => [['account_id' => $this->electricityAccount->id, 'amount' => 10]],
        ])->assertForbidden();
        $this->assertSame(0, Expense::query()->count());
    }
}

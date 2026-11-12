<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Expense;
use App\Models\ExpenseSupport;
use App\Support\Accounting\ExpenseStatus;
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

    public function test_the_summary_nets_support_money_against_counted_expenses(): void
    {
        $this->asManager();
        $id = $this->expenseEntity->id;
        $expense = fn (float $amount, string $currency, string $status, string $date = '2026-11-12') => Expense::factory()->create([
            'account_id' => $this->electricityAccount->id, 'expense_entity_id' => $id, 'amount' => $amount, 'currency' => $currency, 'status' => $status, 'expense_date' => $date,
        ]);
        $expense(500, 'USD', ExpenseStatus::PAID);
        $expense(100, 'USD', ExpenseStatus::PENDING_APPROVAL);
        $expense(999, 'USD', ExpenseStatus::REJECTED);   // not counted
        $expense(999, 'USD', ExpenseStatus::CANCELLED);  // not counted
        $expense(200000, 'KHR', ExpenseStatus::APPROVED);
        $expense(50, 'USD', ExpenseStatus::PAID, '2026-10-01'); // outside the dates below

        $support = fn (array $a) => ExpenseSupport::query()->create([...['name' => 'P', 'support_date' => '2026-11-12', 'expense_entity_id' => $id], ...$a]);
        $support(['kind' => 'money', 'amount' => 150, 'currency' => 'USD']);
        $support(['kind' => 'money', 'amount' => 50000, 'currency' => 'KHR']);
        $support(['kind' => 'food', 'items' => 'Fruit']);

        $data = $this->getJson("/api/v1/expense-supports/summary?expense_entity_id={$id}&from=2026-11-01&to=2026-11-30")->assertOk()->json('data');

        $block = $data['summary'][0];
        $this->assertSame($id, $block['expense_entity']['id']);
        $this->assertSame([2, 1], [$block['money_count'], $block['food_count']]);
        $lines = collect($block['currencies'])->keyBy('currency');
        $this->assertEquals(['currency' => 'USD', 'expenses' => 600, 'support' => 150, 'net' => 450], $lines['USD']);
        $this->assertEquals(['currency' => 'KHR', 'expenses' => 200000, 'support' => 50000, 'net' => 150000], $lines['KHR']);
        $this->assertCount(3, $data['rows']);
        $this->assertSame('12-11-2026', $data['rows'][0]['support_date']);
    }

    public function test_the_support_list_downloads_as_excel_and_pdf(): void
    {
        $this->asManager();
        ExpenseSupport::query()->create(['name' => 'Aunt', 'support_date' => '2026-11-12', 'kind' => 'money', 'amount' => 100, 'currency' => 'USD', 'expense_entity_id' => $this->expenseEntity->id]);

        $excel = $this->get('/api/v1/expense-supports/excel')->assertOk();
        $this->assertStringStartsWith('PK', (string) $excel->getContent());
        $pdf = $this->get('/api/v1/expense-supports/pdf')->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
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
        $this->getJson('/api/v1/expense-supports/summary')->assertForbidden();
        $this->get('/api/v1/expense-supports/excel')->assertForbidden();
    }
}

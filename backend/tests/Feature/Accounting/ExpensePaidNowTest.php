<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Expense;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Add expense / Add many: "Paid now" saves them approved and paid in one go. */
class ExpensePaidNowTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private const ALL = [Permissions::EXPENSE_VIEW, Permissions::EXPENSE_CREATE, Permissions::EXPENSE_APPROVE, Permissions::EXPENSE_PAY];

    private function one(array $extra = []): array
    {
        return [
            'account_id' => $this->electricityAccount->id, 'expense_entity_id' => $this->expenseEntity->id,
            'expense_date' => '2026-11-10', 'amount' => 25, 'currency' => 'USD', ...$extra,
        ];
    }

    public function test_add_one_paid_now_is_saved_paid_on_the_expense_date(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->setUpChartOfAccounts();

        $id = $this->postJson('/api/v1/expenses', $this->one(['paid_now' => true, 'cash_account_id' => $this->cashAccount->id]))
            ->assertCreated()->json('data.id');

        $e = Expense::query()->findOrFail($id);
        $this->assertSame(ExpenseStatus::PAID, $e->status);
        $this->assertSame($this->cashAccount->id, $e->cash_account_id);
        $this->assertSame('2026-11-10', $e->paid_at->toDateString());
    }

    public function test_add_many_paid_now_pays_every_line_on_the_paid_date(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->setUpChartOfAccounts();

        $this->postJson('/api/v1/expenses/batch', [
            'expense_date' => '2026-11-10', 'expense_entity_id' => $this->expenseEntity->id, 'currency' => 'USD',
            'paid_now' => true, 'cash_account_id' => $this->cashAccount->id, 'paid_date' => '2026-11-11',
            'lines' => [['account_id' => $this->electricityAccount->id, 'amount' => 10], ['account_id' => $this->electricityAccount->id, 'amount' => 5]],
        ])->assertCreated();

        $this->assertSame(2, Expense::query()->where('status', ExpenseStatus::PAID)->whereDate('paid_at', '2026-11-11')->count());
    }

    public function test_paid_now_needs_approve_and_pay_and_a_cash_account(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_VIEW, Permissions::EXPENSE_CREATE, Permissions::EXPENSE_APPROVE]);
        $this->setUpChartOfAccounts();
        $this->postJson('/api/v1/expenses', $this->one(['paid_now' => true, 'cash_account_id' => $this->cashAccount->id]))->assertForbidden();

        $this->actingAsAdminWithPermissions(self::ALL);
        $this->postJson('/api/v1/expenses', $this->one(['paid_now' => true]))->assertUnprocessable()->assertJsonValidationErrors('cash_account_id');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_approve_and_pay_holder_may_approve_own_pending_expense(): void
    {
        $user = $this->actingAsAdminWithPermissions(self::ALL);
        $this->setUpChartOfAccounts();
        $mine = Expense::factory()->create([
            'account_id' => $this->electricityAccount->id, 'expense_entity_id' => $this->expenseEntity->id,
            'amount' => 10, 'currency' => 'USD', 'status' => ExpenseStatus::PENDING_APPROVAL, 'created_by' => $user->id,
        ]);

        $this->postJson('/api/v1/expenses/bulk-approve', ['ids' => [$mine->id]])->assertOk()->assertJsonPath('data.done', [$mine->id]);
        $this->assertSame(ExpenseStatus::APPROVED, $mine->fresh()->status);
    }
}

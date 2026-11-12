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

/** Expenses list: tick several, then Approve selected / Pay selected. */
class ExpenseBulkActionsTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private function expense(string $status, float $amount = 10): Expense
    {
        return Expense::factory()->create([
            'account_id' => $this->electricityAccount->id, 'expense_entity_id' => $this->expenseEntity->id,
            'amount' => $amount, 'currency' => 'USD', 'status' => $status, 'created_by' => null,
        ]);
    }

    public function test_approve_selected_approves_pending_ones_and_skips_the_rest(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_VIEW, Permissions::EXPENSE_APPROVE]);
        $this->setUpChartOfAccounts();
        $a = $this->expense(ExpenseStatus::PENDING_APPROVAL);
        $b = $this->expense(ExpenseStatus::PENDING_APPROVAL);
        $paid = $this->expense(ExpenseStatus::PAID);

        $this->postJson('/api/v1/expenses/bulk-approve', ['ids' => [$a->id, $b->id, $paid->id]])
            ->assertOk()->assertJsonPath('data.done', [$a->id, $b->id])->assertJsonPath('data.skipped.0.id', $paid->id);

        $this->assertSame(ExpenseStatus::APPROVED, $a->fresh()->status);
        $this->assertSame(ExpenseStatus::APPROVED, $b->fresh()->status);
    }

    public function test_pay_selected_pays_approved_ones_from_one_account_on_one_date(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_VIEW, Permissions::EXPENSE_PAY]);
        $this->setUpChartOfAccounts();
        $a = $this->expense(ExpenseStatus::APPROVED, 30);
        $b = $this->expense(ExpenseStatus::APPROVED, 20);
        $pending = $this->expense(ExpenseStatus::PENDING_APPROVAL);
        $paid = $this->expense(ExpenseStatus::PAID);

        $response = $this->postJson('/api/v1/expenses/bulk-pay', [
            'ids' => [$a->id, $b->id, $pending->id, $paid->id], 'cash_account_id' => $this->cashAccount->id, 'paid_date' => '2026-11-12',
        ])->assertOk();

        $response->assertJsonPath('data.done', [$a->id, $b->id]);
        $this->assertCount(2, $response->json('data.skipped'));
        foreach ([$a, $b] as $e) {
            $e->refresh();
            $this->assertSame(ExpenseStatus::PAID, $e->status);
            $this->assertSame($this->cashAccount->id, $e->cash_account_id);
            $this->assertSame('2026-11-12', $e->paid_at->toDateString());
        }
        $this->assertSame(ExpenseStatus::PENDING_APPROVAL, $pending->fresh()->status);
    }

    public function test_each_needs_its_permission_and_a_cash_account(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_VIEW]);
        $this->setUpChartOfAccounts();
        $a = $this->expense(ExpenseStatus::APPROVED);

        $this->postJson('/api/v1/expenses/bulk-pay', ['ids' => [$a->id], 'cash_account_id' => $this->cashAccount->id])->assertForbidden();
        $this->postJson('/api/v1/expenses/bulk-approve', ['ids' => [$a->id]])->assertOk()->assertJsonPath('data.done', []);

        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_PAY]);
        $this->postJson('/api/v1/expenses/bulk-pay', ['ids' => [$a->id], 'cash_account_id' => $this->electricityAccount->id])
            ->assertUnprocessable()->assertJsonValidationErrors('cash_account_id');
        $this->assertSame(ExpenseStatus::APPROVED, $a->fresh()->status);
    }
}

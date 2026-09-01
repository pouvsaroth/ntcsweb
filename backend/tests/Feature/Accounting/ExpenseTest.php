<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Accounting\TransactionType;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    public function test_creating_an_expense_defaults_to_pending_approval(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE]);
        $this->setUpChartOfAccounts();

        $response = $this->postJson('/api/v1/expenses', [
            'account_id' => $this->electricityAccount->id,
            'amount' => 30,
            'description' => 'August electricity bill',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', ExpenseStatus::PENDING_APPROVAL);
        $response->assertJsonPath('data.expense_number', fn ($n) => str_starts_with($n, 'EXP-'.now()->year.'-'));

        $log = AuditLog::where('auditable_type', Expense::class)->where('action', AuditAction::EXPENSE_CREATED)->firstOrFail();
        $this->assertStringContainsString('30', $log->description);
    }

    public function test_the_creator_cannot_approve_their_own_expense(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE, Permissions::EXPENSE_APPROVE]);
        $this->setUpChartOfAccounts();

        $expenseId = $this->postJson('/api/v1/expenses', [
            'account_id' => $this->electricityAccount->id,
            'amount' => 30,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/expenses/{$expenseId}/approve", [])->assertUnprocessable();
        $this->assertSame(ExpenseStatus::PENDING_APPROVAL, Expense::find($expenseId)->status);
    }

    public function test_a_different_user_can_approve_the_expense(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE, Permissions::EXPENSE_APPROVE]);
        $this->setUpChartOfAccounts();

        $expenseId = $this->postJson('/api/v1/expenses', [
            'account_id' => $this->electricityAccount->id,
            'amount' => 30,
        ])->assertCreated()->json('data.id');

        $approver = User::factory()->forTenant($this->tenant)->create();
        $approver->attachRoles($this->admin->roles()->first());
        $this->actingAsTenantUser($approver);
        // Re-point the cached tenant relation, same reason as HasChartOfAccounts — see its docblock.
        $approver->setRelation('tenant', $this->tenant);

        $response = $this->postJson("/api/v1/expenses/{$expenseId}/approve", []);
        $response->assertOk();
        $response->assertJsonPath('data.status', ExpenseStatus::APPROVED);
    }

    public function test_rejecting_an_expense_requires_a_reason(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE, Permissions::EXPENSE_REJECT]);
        $this->setUpChartOfAccounts();

        $expenseId = $this->postJson('/api/v1/expenses', ['account_id' => $this->electricityAccount->id, 'amount' => 30])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/expenses/{$expenseId}/reject", [])->assertUnprocessable();

        $response = $this->postJson("/api/v1/expenses/{$expenseId}/reject", ['reason' => 'Missing receipt']);
        $response->assertOk();
        $response->assertJsonPath('data.status', ExpenseStatus::REJECTED);
    }

    public function test_paying_an_approved_expense_posts_the_ledger_entry(): void
    {
        $admin = $this->actingAsAdminWithPermissions([
            Permissions::EXPENSE_CREATE, Permissions::EXPENSE_APPROVE, Permissions::EXPENSE_PAY,
        ]);
        $this->setUpChartOfAccounts();

        $expenseId = $this->postJson('/api/v1/expenses', ['account_id' => $this->electricityAccount->id, 'amount' => 30])
            ->assertCreated()->json('data.id');

        $approver = User::factory()->forTenant($this->tenant)->create();
        $approver->attachRoles($admin->roles()->first());
        $this->actingAsTenantUser($approver);
        $approver->setRelation('tenant', $this->tenant);
        $this->postJson("/api/v1/expenses/{$expenseId}/approve", [])->assertOk();

        $this->actingAsTenantUser($admin);
        $admin->setRelation('tenant', $this->tenant);

        $response = $this->postJson("/api/v1/expenses/{$expenseId}/pay", ['cash_account_id' => $this->cashAccount->id]);
        $response->assertOk();
        $response->assertJsonPath('data.status', ExpenseStatus::PAID);

        $transaction = FinancialTransaction::where('type', TransactionType::EXPENSE)->firstOrFail();
        $this->assertSame($this->electricityAccount->id, $transaction->debit_account_id);
        $this->assertSame($this->cashAccount->id, $transaction->credit_account_id);
        $this->assertSame('30.00', (string) $transaction->amount);

        $log = AuditLog::where('auditable_type', Expense::class)->where('action', AuditAction::EXPENSE_PAID)->firstOrFail();
        $this->assertNotNull($log);
    }

    public function test_an_expense_cannot_be_paid_before_approval(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_CREATE, Permissions::EXPENSE_PAY]);
        $this->setUpChartOfAccounts();

        $expenseId = $this->postJson('/api/v1/expenses', ['account_id' => $this->electricityAccount->id, 'amount' => 30])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/expenses/{$expenseId}/pay", ['cash_account_id' => $this->cashAccount->id])->assertUnprocessable();
    }

    public function test_a_paid_expense_cannot_be_cancelled(): void
    {
        $admin = $this->actingAsAdminWithPermissions([
            Permissions::EXPENSE_CREATE, Permissions::EXPENSE_APPROVE, Permissions::EXPENSE_PAY, Permissions::EXPENSE_CANCEL,
        ]);
        $this->setUpChartOfAccounts();

        $expenseId = $this->postJson('/api/v1/expenses', ['account_id' => $this->electricityAccount->id, 'amount' => 30])
            ->assertCreated()->json('data.id');

        $approver = User::factory()->forTenant($this->tenant)->create();
        $approver->attachRoles($admin->roles()->first());
        $this->actingAsTenantUser($approver);
        $approver->setRelation('tenant', $this->tenant);
        $this->postJson("/api/v1/expenses/{$expenseId}/approve", [])->assertOk();

        $this->actingAsTenantUser($admin);
        $admin->setRelation('tenant', $this->tenant);
        $this->postJson("/api/v1/expenses/{$expenseId}/pay", ['cash_account_id' => $this->cashAccount->id])->assertOk();

        $this->postJson("/api/v1/expenses/{$expenseId}/cancel", ['reason' => 'test'])->assertUnprocessable();
    }

    public function test_creating_an_expense_requires_the_expense_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpChartOfAccounts();

        $this->postJson('/api/v1/expenses', ['account_id' => $this->electricityAccount->id, 'amount' => 30])->assertForbidden();
    }

    public function test_viewing_expenses_requires_the_expense_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/expenses')->assertForbidden();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\ApprovalGroup;
use App\Models\Expense;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Approvals\DocumentType;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/**
 * Expenses in Approval Flow → Flow Setting — see ExpenseService::create()
 * and ExpenseController::approve(). The flow throughout: group 1 (A), then
 * group 2 (B and the admin). A and B hold no expense permission at all.
 */
class ExpenseApprovalFlowTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private User $a;

    private User $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdminWithPermissions([
            Permissions::EXPENSE_VIEW, Permissions::EXPENSE_CREATE, Permissions::EXPENSE_APPROVE, Permissions::APPROVAL_GROUPS_MANAGE,
        ]);
        $this->setUpChartOfAccounts();

        [$this->a, $this->b] = User::factory()->forTenant($this->tenant)->count(2)->create()->all();

        $group1 = ApprovalGroup::factory()->create(['name' => 'Group 1']);
        $group1->members()->create(['user_id' => $this->a->id]);
        $group2 = ApprovalGroup::factory()->create(['name' => 'Group 2']);
        $group2->members()->create(['user_id' => $this->b->id]);
        $group2->members()->create(['user_id' => $this->admin->id]);

        $this->putJson('/api/v1/approval-flows/'.DocumentType::EXPENSE, ['group_ids' => [$group1->id, $group2->id]])->assertOk();
    }

    private function actAs(User $user): void
    {
        $this->actingAsTenantUser($user);
        // Re-point the cached tenant relation, same reason as HasChartOfAccounts — see its docblock.
        $user->setRelation('tenant', $this->tenant);
    }

    /** A user outside every group who can create (and view) expenses. */
    private function creator(): User
    {
        $creator = User::factory()->forTenant($this->tenant)->create();
        $creator->attachRoles($this->admin->roles()->first());

        return $creator;
    }

    private function createExpense(): int
    {
        return $this->postJson('/api/v1/expenses', [
            'account_id' => $this->electricityAccount->id,
            'amount' => 30,
        ])->assertCreated()->json('data.id');
    }

    public function test_an_expense_created_by_a_member_of_the_last_steps_group_is_approved_straight_away(): void
    {
        $id = $this->createExpense();

        $expense = Expense::query()->findOrFail($id);
        $this->assertSame(ExpenseStatus::APPROVED, $expense->status);
        $this->assertSame($this->admin->id, $expense->approved_by);
        $this->assertFalse(UserNotification::where('type', NotificationType::EXPENSE_SUBMITTED)->exists());
    }

    public function test_anyone_elses_expense_goes_through_each_step_in_order(): void
    {
        $creator = $this->creator();
        $this->actAs($creator);
        $id = $this->createExpense();

        $this->assertSame(ExpenseStatus::PENDING_APPROVAL, Expense::find($id)->status);
        $this->assertTrue(UserNotification::where('recipient_id', $this->a->id)->where('type', NotificationType::EXPENSE_SUBMITTED)->exists());

        // Step 1 is group 1's — group 2 can't jump ahead.
        $this->actAs($this->b);
        $this->postJson("/api/v1/expenses/{$id}/approve")->assertForbidden();

        $this->actAs($this->a);
        $this->postJson("/api/v1/expenses/{$id}/approve")->assertOk()->assertJsonPath('data.status', ExpenseStatus::PENDING_APPROVAL);
        $this->assertTrue(UserNotification::where('recipient_id', $this->b->id)->where('type', NotificationType::EXPENSE_SUBMITTED)->exists());

        $this->actAs($this->b);
        $this->postJson("/api/v1/expenses/{$id}/approve")->assertOk()->assertJsonPath('data.status', ExpenseStatus::APPROVED);

        $this->assertSame($this->b->id, Expense::find($id)->approved_by);
        $this->assertTrue(UserNotification::where('recipient_id', $creator->id)->where('type', NotificationType::EXPENSE_APPROVED)->exists());
    }

    public function test_a_waiting_expense_is_in_its_current_groups_approvals_queue(): void
    {
        $this->actAs($this->creator());
        $id = $this->createExpense();

        $this->actAs($this->a);
        $this->getJson('/api/v1/expenses?approval_queue=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.approval_flow.step', 1)
            ->assertJsonPath('data.0.approval_flow.can_act', true);

        $this->actAs($this->b);
        $this->getJson('/api/v1/expenses?approval_queue=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_current_group_can_reject_and_the_creator_is_told(): void
    {
        $creator = $this->creator();
        $this->actAs($creator);
        $id = $this->createExpense();

        $this->actAs($this->a);
        $this->postJson("/api/v1/expenses/{$id}/reject", ['reason' => 'No receipt'])->assertOk();

        $this->assertSame(ExpenseStatus::REJECTED, Expense::find($id)->status);
        $this->assertTrue(UserNotification::where('recipient_id', $creator->id)->where('type', NotificationType::EXPENSE_REJECTED)->exists());
    }
}

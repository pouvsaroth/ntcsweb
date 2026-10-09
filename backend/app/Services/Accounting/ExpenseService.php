<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Expense;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Notifications\NotificationService;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Approvals\DocumentType;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Notifications\NotificationType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Expense approval workflow: create -> PENDING_APPROVAL -> APPROVED ->
 * PAID, or REJECTED/CANCELLED along the way. Only pay() ever touches the
 * ledger (via FinancialTransactionService::postExpensePayment()) — an
 * approved-but-unpaid expense has no financial-transaction row yet, matching
 * cash-basis accounting (an expense is only "spent" once money actually
 * leaves an account).
 */
final class ExpenseService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AccountingNumberGenerator $numbers,
        private readonly FinancialTransactionService $transactions,
        private readonly AuditLogger $audit,
        private readonly ApprovalFlow $flow,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{expense_date?:string, account_id:int, amount:float, payment_method?:string|null, vendor?:string|null, description?:string|null, reference_number?:string|null, status?:string}  $data
     */
    public function create(array $data, User $actor): Expense
    {
        $expense = $this->createRow($data, $actor);

        if ($expense->status !== ExpenseStatus::PENDING_APPROVAL || ! $this->flow->hasFlow(DocumentType::EXPENSE)) {
            return $expense;
        }

        // Approval Flow → Flow Setting has an Expense flow: an expense
        // created by a member of its last step's group needs nobody else's
        // say, so it's approved straight away; anyone else's waits on the
        // first step's group, who are told.
        if ($this->flow->isLastStepApprover(DocumentType::EXPENSE, $actor)) {
            return $this->markApproved($expense, $actor, "Auto-approved expense {$expense->expense_number} — created by its flow's last approver");
        }

        $this->notifyApprovers($expense, $this->flow->submitRecipients($expense, fn () => collect()));

        return $expense;
    }

    /**
     * @param  array{expense_date?:string, account_id:int, amount:float, payment_method?:string|null, vendor?:string|null, description?:string|null, reference_number?:string|null, status?:string}  $data
     */
    private function createRow(array $data, User $actor): Expense
    {
        return DB::transaction(function () use ($data, $actor) {
            $tenant = $this->context->getOrFail();

            $expense = Expense::query()->create([
                'expense_number' => $this->numbers->nextExpenseNumber($tenant),
                'expense_date' => $data['expense_date'] ?? now()->toDateString(),
                'account_id' => $data['account_id'],
                'amount' => round((float) $data['amount'], 2),
                'payment_method' => $data['payment_method'] ?? null,
                'vendor' => $data['vendor'] ?? null,
                'description' => $data['description'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'status' => $data['status'] ?? ExpenseStatus::PENDING_APPROVAL,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::EXPENSE_CREATED,
                'Expenses',
                $expense,
                new: ['amount' => (float) $expense->amount, 'account_id' => $expense->account_id],
                description: "Created expense {$expense->expense_number} — \${$expense->amount}",
                actor: $actor,
            );

            return $expense;
        });
    }

    /**
     * The final approval — with an Expense flow, only once its last step is
     * approved (see ExpenseController::approve()). Segregation of duties
     * without a flow: whoever created the expense can never approve it. With
     * a flow, the flow decides who approves (and its last group's own
     * expenses are approved on creation — see create()).
     */
    public function approve(Expense $expense, User $actor): Expense
    {
        if (! $this->flow->hasFlow(DocumentType::EXPENSE) && $expense->created_by === $actor->getKey()) {
            throw ValidationException::withMessages(['status' => 'You cannot approve an expense you created yourself.']);
        }

        $expense = $this->markApproved($expense, $actor, "Approved expense {$expense->expense_number}");

        $this->notifyCreator($expense, $actor, NotificationType::EXPENSE_APPROVED);

        return $expense;
    }

    private function markApproved(Expense $expense, User $actor, string $description): Expense
    {
        return DB::transaction(function () use ($expense, $actor, $description) {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($expense->status !== ExpenseStatus::PENDING_APPROVAL) {
                throw ValidationException::withMessages(['status' => 'Only a pending expense can be approved.']);
            }

            $expense->update([
                'status' => ExpenseStatus::APPROVED,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ]);

            $this->audit->log(
                AuditAction::EXPENSE_APPROVED,
                'Expenses',
                $expense,
                description: $description,
                actor: $actor,
            );

            return $expense;
        });
    }

    /** @param  Collection<int, User>  $recipients  tells an approval step's group it's their turn */
    public function notifyApprovers(Expense $expense, Collection $recipients): void
    {
        $this->notifications->notifyMany($recipients, NotificationType::EXPENSE_SUBMITTED, ['reference' => $expense->expense_number], link: '/admin/approvals/queue');
    }

    /** @param  array<string, mixed>  $extra */
    private function notifyCreator(Expense $expense, User $actor, string $type, array $extra = []): void
    {
        $creator = $expense->created_by !== null ? User::query()->find($expense->created_by) : null;

        if ($creator !== null && ! $creator->is($actor)) {
            $this->notifications->notifyMany(collect([$creator]), $type, ['reference' => $expense->expense_number, ...$extra], link: "/admin/expenses/{$expense->id}");
        }
    }

    public function reject(Expense $expense, string $reason, User $actor): Expense
    {
        $expense = DB::transaction(function () use ($expense, $reason, $actor) {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($expense->status !== ExpenseStatus::PENDING_APPROVAL) {
                throw ValidationException::withMessages(['status' => 'Only a pending expense can be rejected.']);
            }

            $expense->update(['status' => ExpenseStatus::REJECTED, 'rejected_reason' => $reason]);

            $this->audit->log(
                AuditAction::EXPENSE_REJECTED,
                'Expenses',
                $expense,
                new: ['reason' => $reason],
                description: "Rejected expense {$expense->expense_number}: {$reason}",
                actor: $actor,
            );

            return $expense;
        });

        $this->notifyCreator($expense, $actor, NotificationType::EXPENSE_REJECTED, ['reason' => $reason]);

        return $expense;
    }

    public function pay(Expense $expense, Account $cashAccount, User $actor, ?string $date = null): Expense
    {
        return DB::transaction(function () use ($expense, $cashAccount, $actor, $date) {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->getKey())->with('account')->lockForUpdate()->firstOrFail();

            if ($expense->status !== ExpenseStatus::APPROVED) {
                throw ValidationException::withMessages(['status' => 'Only an approved expense can be paid.']);
            }

            if (! $cashAccount->is_bank_or_cash) {
                throw ValidationException::withMessages(['cash_account_id' => 'Expenses can only be paid from a Cash/Bank account.']);
            }

            $payDate = $date ?? now()->toDateString();

            $this->transactions->postExpensePayment(
                $expense->account,
                $cashAccount,
                (float) $expense->amount,
                $payDate,
                $expense,
                $actor,
            );

            $expense->update([
                'status' => ExpenseStatus::PAID,
                'cash_account_id' => $cashAccount->getKey(),
                'paid_at' => Carbon::parse($payDate),
            ]);

            $this->audit->log(
                AuditAction::EXPENSE_PAID,
                'Expenses',
                $expense,
                new: ['cash_account' => $cashAccount->auditDisplayName(), 'amount' => (float) $expense->amount],
                description: "Paid expense {$expense->expense_number} — \${$expense->amount} from {$cashAccount->auditDisplayName()}",
                actor: $actor,
            );

            return $expense;
        });
    }

    /** Only DRAFT/PENDING_APPROVAL/APPROVED can be cancelled — a PAID expense needs a manual adjustment instead (spec section 28: never silently edit a posted transaction). */
    public function cancel(Expense $expense, string $reason, User $actor): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $actor) {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($expense->isClosed()) {
                throw ValidationException::withMessages(['status' => 'This expense is already paid, rejected, or cancelled.']);
            }

            $expense->update([
                'status' => ExpenseStatus::CANCELLED,
                'cancellation_reason' => $reason,
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
            ]);

            $this->audit->log(
                AuditAction::EXPENSE_CANCELLED,
                'Expenses',
                $expense,
                new: ['reason' => $reason],
                description: "Cancelled expense {$expense->expense_number}: {$reason}",
                actor: $actor,
            );

            return $expense;
        });
    }
}

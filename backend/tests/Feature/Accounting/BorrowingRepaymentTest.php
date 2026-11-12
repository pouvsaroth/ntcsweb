<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Borrowing;
use App\Models\Expense;
use App\Models\ExpenseEntity;
use App\Models\FinancialTransaction;
use App\Models\Lender;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Accounting\TransactionType;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Accounting > Borrowings > Repayments: payments back, and the ledger kept in step. */
class BorrowingRepaymentTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private ExpenseEntity $family;

    protected function setUp(): void
    {
        parent::setUp();
        // Midday: the test's dates (UTC) and the school's day (Phnom Penh) agree.
        // Between 00:00 and 07:00 Phnom Penh they are a day apart.
        $this->travelTo(now()->setTime(12, 0));
        $this->actingAsAdminWithPermissions([Permissions::BORROWINGS_VIEW, Permissions::BORROWING_LOANS_CREATE, Permissions::BORROWING_LOANS_UPDATE, Permissions::BORROWING_LOANS_DELETE, Permissions::BORROWING_REPAYMENTS_CREATE, Permissions::BORROWING_REPAYMENTS_DELETE, Permissions::LENDERS_CREATE, Permissions::LENDERS_UPDATE, Permissions::LENDERS_DELETE, Permissions::REPORTS_FINANCIAL_VIEW]);
        $this->setUpChartOfAccounts();
        $this->family = ExpenseEntity::factory()->create(['name' => 'Family']);
    }

    /** $1,200 at 1% flat a month over 12 months from today: $100 principal + $12 interest. */
    private function borrow(array $overrides = []): array
    {
        return $this->postJson('/api/v1/borrowings', [
            'lender_id' => Lender::factory()->create(['name' => 'Sok Dara'])->id,
            'expense_entity_id' => $this->family->id,
            'amount' => 1200, 'currency' => 'USD',
            'received_on' => now()->subMonths(2)->toDateString(),
            'cash_account_id' => $this->cashAccount->id,
            'interest_type' => 'flat', 'interest_rate' => 1, 'interest_period' => 'month',
            'term_months' => 12, 'first_payment_on' => now()->subMonth()->toDateString(),
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    private function cash(): float
    {
        $id = $this->cashAccount->id;

        return (float) FinancialTransaction::query()->where('debit_account_id', $id)->sum('amount') - (float) FinancialTransaction::query()->where('credit_account_id', $id)->sum('amount');
    }

    public function test_money_received_goes_into_cash_and_loans_payable(): void
    {
        $loan = $this->borrow();
        $this->assertTrue($loan['posted']);

        $payable = Account::query()->where('code', '2100')->firstOrFail();
        $this->assertSame('Loans Payable', $payable->name);
        $receipt = FinancialTransaction::query()->where('type', TransactionType::LOAN)->sole();
        $this->assertSame($this->cashAccount->id, $receipt->debit_account_id);
        $this->assertSame($payable->id, $receipt->credit_account_id);
        $this->assertEquals(1200, $this->cash());

        $noAccount = $this->borrow(['cash_account_id' => null]);
        $this->assertFalse($noAccount['posted']);
    }

    public function test_a_repayment_lowers_the_debt_and_its_interest_is_a_family_expense(): void
    {
        $loan = $this->borrow();
        $this->assertSame(1, $loan['late_count']);
        $this->assertSame(100, (int) $loan['next_payment']['due_principal']);

        $paid = $this->postJson("/api/v1/borrowings/{$loan['id']}/repayments", [
            'paid_on' => now()->toDateString(), 'principal' => 100, 'interest' => 12, 'cash_account_id' => $this->cashAccount->id,
        ])->assertCreated()->json('data');

        $this->assertEquals(1100, $paid['outstanding']);
        $this->assertTrue($paid['schedule'][0]['paid']);
        $this->assertSame(2, $paid['next_payment']['no']);
        $this->assertSame(0, $paid['late_count']);
        $this->assertCount(1, $paid['repayments']);
        $this->assertEquals(1200 - 112, $this->cash());

        $expense = Expense::query()->sole();
        $this->assertSame(ExpenseStatus::PAID, $expense->status);
        $this->assertSame($this->family->id, $expense->expense_entity_id);
        $this->assertSame('Loan Interest', Account::query()->find($expense->account_id)->name);
        $this->assertEquals(12, $expense->amount);

        $this->getJson('/api/v1/borrowing-repayments?search=sok')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.loan.borrowed_for.name', 'Family');
    }

    public function test_paying_it_all_marks_it_paid_off_and_removing_a_payment_undoes_the_ledger(): void
    {
        $loan = $this->borrow();
        $this->postJson("/api/v1/borrowings/{$loan['id']}/repayments", ['paid_on' => now()->toDateString(), 'principal' => 1300, 'cash_account_id' => $this->cashAccount->id])
            ->assertUnprocessable()->assertJsonValidationErrors('principal');

        $paid = $this->postJson("/api/v1/borrowings/{$loan['id']}/repayments", [
            'paid_on' => now()->toDateString(), 'principal' => 1200, 'interest' => 30, 'cash_account_id' => $this->cashAccount->id,
        ])->assertCreated()->json('data');
        $this->assertSame('paid_off', $paid['status']);
        $this->assertNull($paid['next_payment']);
        $this->assertEquals(-30, $this->cash());

        $this->deleteJson("/api/v1/borrowing-repayments/{$paid['repayments'][0]['id']}")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertEquals(1200, $this->cash());
        $this->assertSame(ExpenseStatus::CANCELLED, Expense::query()->sole()->status);
    }

    public function test_with_payments_the_terms_are_locked_and_it_cannot_be_cancelled_or_deleted(): void
    {
        $loan = $this->borrow();
        $this->postJson("/api/v1/borrowings/{$loan['id']}/repayments", ['paid_on' => now()->toDateString(), 'principal' => 100, 'cash_account_id' => $this->cashAccount->id])->assertCreated();

        $this->putJson("/api/v1/borrowings/{$loan['id']}", ['amount' => 1500])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->putJson("/api/v1/borrowings/{$loan['id']}", ['purpose' => 'Car'])->assertOk()->assertJsonPath('data.purpose', 'Car');
        $this->postJson("/api/v1/borrowings/{$loan['id']}/cancel")->assertUnprocessable();
        $this->deleteJson("/api/v1/borrowings/{$loan['id']}")->assertUnprocessable();
    }

    public function test_changing_or_deleting_an_unpaid_loan_reverses_its_receipt(): void
    {
        $loan = $this->borrow();
        $this->putJson("/api/v1/borrowings/{$loan['id']}", ['amount' => 1500])->assertOk()->assertJsonPath('data.posted', true);
        $this->assertEquals(1500, $this->cash());

        $this->deleteJson("/api/v1/borrowings/{$loan['id']}")->assertNoContent();
        $this->assertEquals(0, $this->cash());
        $this->assertSame(0, Borrowing::query()->count());
    }

    public function test_cash_flow_shows_loans_in_and_out_and_still_adds_up(): void
    {
        $loan = $this->borrow(['received_on' => now()->toDateString(), 'first_payment_on' => now()->addMonth()->toDateString()]);
        $this->postJson("/api/v1/borrowings/{$loan['id']}/repayments", ['paid_on' => now()->toDateString(), 'principal' => 100, 'interest' => 12, 'cash_account_id' => $this->cashAccount->id])->assertCreated();

        $today = now()->toDateString();
        $flow = $this->getJson("/api/v1/accounting/reports/cash-flow?date_from={$today}&date_to={$today}")->assertOk()->json('data');

        $this->assertEquals(1200, $flow['loans_received']);
        $this->assertEquals(100, $flow['loan_repayments']);
        $this->assertEquals(12, $flow['expenses']);
        $this->assertEquals(1088, $flow['closing']);
    }

    public function test_the_summary_totals_owed_late_and_interest_paid_per_currency(): void
    {
        $usd = $this->borrow();
        $this->borrow(['amount' => 4000000, 'currency' => 'KHR', 'interest_type' => 'none', 'cash_account_id' => null, 'first_payment_on' => now()->addMonths(2)->toDateString()]);
        $this->postJson("/api/v1/borrowings/{$usd['id']}/repayments", ['paid_on' => now()->toDateString(), 'principal' => 100, 'interest' => 12, 'cash_account_id' => $this->cashAccount->id])->assertCreated();
        $cancelled = $this->borrow(['amount' => 999]);
        $this->postJson("/api/v1/borrowings/{$cancelled['id']}/cancel")->assertOk();

        $summary = $this->getJson('/api/v1/borrowings/summary')->assertOk()->json('data');

        $this->assertEquals([['currency' => 'KHR', 'amount' => 4000000], ['currency' => 'USD', 'amount' => 1100]], $summary['owed']);
        $this->assertSame(2, $summary['active_loans']);
        $this->assertSame(0, $summary['late_loans']);
        $this->assertSame($usd['id'], $summary['next_payment']['loan_id']);
        $this->assertCount(2, $summary['by_lender']);
        $family = collect($summary['by_borrowed_for'])->firstWhere('currency', 'USD');
        $this->assertSame('Family', $family['name']);
        $this->assertEquals(1200, $family['borrowed']);
        $this->assertEquals(12, $family['paid_interest']);
        $this->assertEquals([['id' => $this->family->id, 'name' => 'Family', 'currency' => 'USD', 'principal' => 100, 'interest' => 12, 'payments' => 1]], $summary['paid']['rows']);

        $this->getJson('/api/v1/borrowings/summary?from=2000-01-01&to=2000-12-31')->assertOk()->assertJsonCount(0, 'data.paid.rows');
    }

    public function test_due_lists_the_next_unpaid_month_of_active_loans(): void
    {
        $late = $this->borrow();
        $this->borrow(['first_payment_on' => now()->addMonths(3)->toDateString()]);

        $this->getJson('/api/v1/borrowings/due')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $late['id'])
            ->assertJsonPath('data.0.next_payment.late', true);
    }

    public function test_a_loan_without_months_is_flexible_paid_any_date_any_amount_or_interest_only(): void
    {
        $loan = $this->borrow(['term_months' => null, 'first_payment_on' => null, 'interest_type' => 'reducing', 'interest_rate' => 2]);

        $this->assertTrue($loan['flexible']);
        $this->assertNull($loan['next_payment']);
        $this->assertSame(0, $loan['late_count']);
        $this->assertEquals(24, $loan['month_interest']);

        // Interest only: the debt stays the same.
        $paid = $this->postJson("/api/v1/borrowings/{$loan['id']}/repayments", ['paid_on' => now()->subDays(40)->toDateString(), 'interest' => 24, 'cash_account_id' => $this->cashAccount->id])
            ->assertCreated()->json('data');
        $this->assertEquals(1200, $paid['outstanding']);

        // Any amount, any date; interest then runs on what is still owed.
        $paid = $this->postJson("/api/v1/borrowings/{$loan['id']}/repayments", ['paid_on' => now()->subDays(3)->toDateString(), 'principal' => 450.5, 'cash_account_id' => $this->cashAccount->id])
            ->assertCreated()->json('data');
        $this->assertEquals(749.5, $paid['outstanding']);
        $this->assertEquals(14.99, $paid['month_interest']);
        $this->assertSame([], $paid['schedule']);

        // Listed on the Repayments tab after the dated loans.
        $dated = $this->borrow();
        $this->getJson('/api/v1/borrowings/due')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $dated['id'])
            ->assertJsonPath('data.1.id', $loan['id']);
    }

    public function test_months_without_a_first_payment_start_a_month_after_receiving(): void
    {
        $loan = $this->borrow(['first_payment_on' => null, 'received_on' => '2026-11-15']);

        $this->assertFalse($loan['flexible']);
        $this->assertSame('2027-11-15', $loan['last_payment_on']);
    }
}

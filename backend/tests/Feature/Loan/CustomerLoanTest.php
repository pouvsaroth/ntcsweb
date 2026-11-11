<?php

declare(strict_types=1);

namespace Tests\Feature\Loan;

use App\Models\CustomerLoan;
use App\Models\FinancialTransaction;
use App\Models\LoanCustomer;
use App\Support\Accounting\TransactionType;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Loan > Owe: money lent to customers, paid back as principal + interest, and the ledger kept in step. */
class CustomerLoanTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private const ALL = [
        Permissions::CUSTOMER_LOANS_VIEW, Permissions::CUSTOMER_LOANS_CREATE, Permissions::CUSTOMER_LOANS_UPDATE, Permissions::CUSTOMER_LOANS_DELETE,
        Permissions::LOAN_CUSTOMERS_VIEW, Permissions::LOAN_CUSTOMERS_DELETE, Permissions::REPORTS_FINANCIAL_VIEW,
    ];

    private LoanCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->setUpChartOfAccounts();
        $this->customer = LoanCustomer::factory()->create(['name' => 'Sok Dara']);
    }

    /** $1,200 at 1% flat a month over 12 months: $100 principal + $12 interest a month. */
    private function lend(array $overrides = []): array
    {
        return $this->postJson('/api/v1/customer-loans', [
            'loan_customer_id' => $this->customer->id,
            'amount' => 1200,
            'currency' => 'USD',
            'lent_on' => now()->subMonths(2)->toDateString(),
            'cash_account_id' => $this->cashAccount->id,
            'interest_type' => 'flat',
            'interest_rate' => 1,
            'interest_period' => 'month',
            'term_months' => 12,
            'first_payment_on' => now()->subMonth()->toDateString(),
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    private function pay(int $loanId, float $principal, float $interest)
    {
        return $this->postJson("/api/v1/customer-loans/{$loanId}/payments", [
            'paid_on' => now()->toDateString(), 'principal' => $principal, 'interest' => $interest, 'cash_account_id' => $this->cashAccount->id,
        ]);
    }

    private function cash(): float
    {
        $id = $this->cashAccount->id;

        return (float) FinancialTransaction::query()->where('debit_account_id', $id)->sum('amount') - (float) FinancialTransaction::query()->where('credit_account_id', $id)->sum('amount');
    }

    public function test_lending_takes_cash_into_loans_receivable_with_a_plan(): void
    {
        $loan = $this->lend();

        $this->assertMatchesRegularExpression('/^L\d{5}$/', $loan['loan_no']);
        $this->assertTrue($loan['posted']);
        $this->assertEquals(112, $loan['monthly_payment']);
        $this->assertEquals(144, $loan['totals']['interest']);
        $this->assertCount(12, $loan['schedule']);
        // First month (a month ago) unpaid, so late.
        $this->assertSame(1, $loan['late_count']);

        $posted = FinancialTransaction::query()->where('type', TransactionType::LENDING)->sole();
        $this->assertSame($this->cashAccount->id, $posted->credit_account_id);
        $this->assertSame('Loans Receivable', $posted->debitAccount->name);
        $this->assertEquals(-1200, $this->cash());
    }

    public function test_interest_only_pays_interest_monthly_and_the_whole_amount_in_the_last_month(): void
    {
        // $3,000 at 2% a month over 12 months: $60 interest a month, the $3,000 with month 12.
        $loan = $this->lend(['amount' => 3000, 'interest_type' => 'interest_only', 'interest_rate' => 2, 'first_payment_on' => '2026-11-29']);

        $rows = $loan['schedule'];
        $this->assertCount(12, $rows);
        $this->assertEquals(['2026-11-29', 3000.0, 0.0, 60.0, 60.0, 3000.0], [$rows[0]['due_on'], $rows[0]['start'], $rows[0]['principal'], $rows[0]['interest'], $rows[0]['total'], $rows[0]['balance']]);
        $this->assertEquals(['2027-10-29', 3000.0, 3000.0, 60.0, 3060.0, 0.0], [$rows[11]['due_on'], $rows[11]['start'], $rows[11]['principal'], $rows[11]['interest'], $rows[11]['total'], $rows[11]['balance']]);
        $this->assertEquals(720, $loan['totals']['interest']);
        $this->assertEquals(60, $loan['next_payment']['total']);

        // Paying one month of interest marks that month paid; the next is month 2.
        $this->pay($loan['id'], 0, 60)->assertCreated();
        $loan = $this->getJson("/api/v1/customer-loans/{$loan['id']}")->assertOk()->json('data');
        $this->assertTrue($loan['schedule'][0]['paid']);
        $this->assertFalse($loan['schedule'][1]['paid']);
        $this->assertSame(2, $loan['next_payment']['no']);
        $this->assertEquals(3000, $loan['outstanding']);
    }

    public function test_interest_only_paying_part_of_the_principal_lowers_the_interest_from_the_next_month(): void
    {
        // $3,000 at 3% a month: $90 a month. Months 1-3 interest, then month 4: $1,500 principal + $90.
        $loan = $this->lend(['amount' => 3000, 'interest_type' => 'interest_only', 'interest_rate' => 3, 'first_payment_on' => '2026-11-29']);
        foreach ([1, 2, 3] as $m) {
            $this->pay($loan['id'], 0, 90)->assertCreated();
        }
        $this->pay($loan['id'], 1500, 90)->assertCreated();

        $loan = $this->getJson("/api/v1/customer-loans/{$loan['id']}")->assertOk()->json('data');
        $rows = $loan['schedule'];
        $row = fn (int $no) => [$rows[$no - 1]['start'], $rows[$no - 1]['balance'], $rows[$no - 1]['principal'], $rows[$no - 1]['interest'], $rows[$no - 1]['paid']];
        $this->assertEquals([3000.0, 1500.0, 1500.0, 90.0, true], $row(4));
        $this->assertEquals([1500.0, 1500.0, 0.0, 45.0, false], $row(5));
        $this->assertEquals([1500.0, 0.0, 1500.0, 45.0, false], $row(12));
        $this->assertSame(5, $loan['next_payment']['no']);
        $this->assertEquals(45, $loan['next_payment']['total']);
        $this->assertEquals(1500, $loan['outstanding']);
        // 4 x 90 + 8 x 45.
        $this->assertEquals(720, $loan['totals']['interest']);
    }

    public function test_the_payment_list_downloads_as_excel_and_the_pdf_page_has_its_rows(): void
    {
        $loan = $this->lend(['amount' => 3000, 'interest_type' => 'interest_only', 'interest_rate' => 2, 'first_payment_on' => '2026-11-29']);

        $excel = $this->get("/api/v1/customer-loans/{$loan['id']}/plan/excel")->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $excel->headers->get('Content-Type'));
        $this->assertStringContainsString('payment-list-'.$loan['loan_no'].'.xlsx', (string) $excel->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $excel->getContent());

        // The PDF page itself (rendering to PDF needs Chrome, so only the HTML is checked).
        $html = view('pdf.customer-loan-plan', [
            'tenant' => $this->tenant, 'logoDataUri' => null, 'khmerFontRegular' => '', 'khmerFontBold' => '', 'generatedAt' => '10-11-2026 09:00',
            'plan' => app(\App\Services\Borrowings\CustomerLoanPlanExport::class)->build(\App\Models\CustomerLoan::query()->findOrFail($loan['id'])),
            'headers' => ['No.', 'Pay date', 'Start amount', 'End amount', 'Principal payment', 'Interest', 'Payment'],
            'money' => fn (float $v) => '$'.number_format($v, 2),
        ])->render();
        $this->assertStringContainsString('29-11-2026', $html);
        $this->assertStringContainsString('29-10-2027', $html);
        $this->assertStringContainsString('$3,060.00', $html);
        $this->assertStringContainsString('$3,720.00', $html);
        $this->assertStringContainsString('Sok Dara', $html);

        // A flexible loan has no list to download; viewing needs the permission.
        $flexible = $this->lend(['term_months' => null]);
        $this->get("/api/v1/customer-loans/{$flexible['id']}/plan/excel")->assertStatus(422);
        $this->actingAsAdminWithPermissions([Permissions::LOAN_CUSTOMERS_VIEW]);
        $this->get("/api/v1/customer-loans/{$loan['id']}/plan/excel")->assertForbidden();
    }

    public function test_a_payment_splits_principal_and_interest_income(): void
    {
        $loan = $this->lend();
        $this->pay($loan['id'], 100, 12)->assertCreated()->assertJsonPath('data.amount', 112);

        $shown = $this->getJson("/api/v1/customer-loans/{$loan['id']}")->assertOk()->json('data');
        $this->assertEquals(1100, $shown['outstanding']);
        $this->assertEquals(12, $shown['paid_interest']);
        $this->assertSame(0, $shown['late_count']);
        $this->assertCount(1, $shown['payments']);

        $interest = FinancialTransaction::query()->where('type', TransactionType::INCOME)->sole();
        $this->assertSame('Loan Interest Income', $interest->creditAccount->name);
        $this->assertEquals(-1200 + 112, $this->cash());
    }

    public function test_paying_it_all_marks_it_paid_off_and_removing_a_payment_undoes_it(): void
    {
        $loan = $this->lend();
        $this->pay($loan['id'], 1300, 0)->assertJsonValidationErrors('principal');
        $payment = $this->pay($loan['id'], 1200, 30)->assertCreated()->json('data');
        $this->getJson("/api/v1/customer-loans/{$loan['id']}")->assertJsonPath('data.status', 'paid_off');
        $this->assertEquals(30, $this->cash());

        $this->deleteJson("/api/v1/customer-loan-payments/{$payment['id']}")->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.outstanding', 1200);
        $this->assertEquals(-1200, $this->cash());
    }

    public function test_with_payments_terms_are_locked_and_it_cannot_be_cancelled_or_deleted(): void
    {
        $loan = $this->lend();
        $this->pay($loan['id'], 100, 12)->assertCreated();

        $this->putJson("/api/v1/customer-loans/{$loan['id']}", ['amount' => 1500])->assertJsonValidationErrors('amount');
        $this->putJson("/api/v1/customer-loans/{$loan['id']}", ['purpose' => 'Motorbike'])->assertOk()->assertJsonPath('data.purpose', 'Motorbike');
        $this->postJson("/api/v1/customer-loans/{$loan['id']}/cancel")->assertJsonValidationErrors('status');
        $this->deleteJson("/api/v1/customer-loans/{$loan['id']}")->assertJsonValidationErrors('status');
        $this->deleteJson("/api/v1/loan-customers/{$this->customer->id}")->assertJsonValidationErrors('name');
    }

    public function test_changing_or_deleting_an_unpaid_loan_reverses_it(): void
    {
        $loan = $this->lend();
        $this->putJson("/api/v1/customer-loans/{$loan['id']}", ['amount' => 1500])->assertOk()->assertJsonPath('data.posted', true);
        $this->assertEquals(-1500, $this->cash());

        $this->deleteJson("/api/v1/customer-loans/{$loan['id']}")->assertNoContent();
        $this->assertEquals(0, $this->cash());
        $this->assertSame(0, CustomerLoan::query()->count());
    }

    public function test_a_flexible_loan_has_no_plan_and_takes_any_payment(): void
    {
        $loan = $this->lend(['term_months' => null, 'first_payment_on' => null, 'interest_type' => 'reducing', 'interest_rate' => 2]);

        $this->assertTrue($loan['flexible']);
        $this->assertSame([], $loan['schedule']);
        $this->assertEquals(24, $loan['month_interest']);
        $this->pay($loan['id'], 0, 24)->assertCreated();
        $this->pay($loan['id'], 200, 0)->assertCreated();
        $this->getJson("/api/v1/customer-loans/{$loan['id']}")->assertJsonPath('data.outstanding', 1000)->assertJsonPath('data.month_interest', 20);
    }

    public function test_cash_flow_shows_lending_collections_and_interest_and_still_adds_up(): void
    {
        $loan = $this->lend(['lent_on' => now()->toDateString(), 'first_payment_on' => now()->addMonth()->toDateString()]);
        $this->pay($loan['id'], 100, 12)->assertCreated();

        $today = now()->toDateString();
        $flow = $this->getJson("/api/v1/accounting/reports/cash-flow?date_from={$today}&date_to={$today}")->assertOk()->json('data');

        $this->assertEquals(1200, $flow['loans_given']);
        $this->assertEquals(100, $flow['loan_collections']);
        $this->assertEquals(12, $flow['loan_interest_received']);
        $this->assertEquals(0, $flow['other_income']);
        $this->assertEquals(-1088, $flow['closing']);
    }

    public function test_summary_and_search(): void
    {
        $this->lend();
        $this->lend(['currency' => 'KHR', 'amount' => 400000, 'interest_type' => 'none', 'interest_rate' => 0, 'term_months' => 4, 'lent_on' => now()->toDateString(), 'first_payment_on' => now()->addDays(10)->toDateString()]);

        $summary = $this->getJson('/api/v1/customer-loans/summary')->assertOk()->json('data');
        $this->assertEquals(1200, $summary['currencies'][0]['owed']);
        $this->assertEquals(112, $summary['currencies'][0]['late']);
        $this->assertEquals(400000, $summary['currencies'][1]['owed']);
        $this->assertEquals(100000, $summary['currencies'][1]['due_soon']);
        $this->assertSame(1, $summary['late_loans']);

        $this->getJson('/api/v1/customer-loans?search=dara')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/customer-loans?search=nobody')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $loan = CustomerLoan::factory()->create(['loan_customer_id' => $this->customer->id]);
        $this->actingAsAdminWithPermissions([Permissions::CUSTOMER_LOANS_VIEW]);

        $this->getJson('/api/v1/customer-loans')->assertOk();
        $this->postJson('/api/v1/customer-loans', ['loan_customer_id' => $this->customer->id, 'amount' => 10, 'currency' => 'USD', 'lent_on' => now()->toDateString(), 'interest_type' => 'none'])->assertForbidden();
        $this->pay($loan->id, 10, 0)->assertForbidden();
        $this->putJson("/api/v1/customer-loans/{$loan->id}", ['purpose' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/customer-loans/{$loan->id}")->assertForbidden();
    }
}

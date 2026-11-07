<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Borrowing;
use App\Models\ExpenseEntity;
use App\Models\Lender;
use App\Services\Borrowings\BorrowingSchedule;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Accounting > Borrowings: lenders, loans and their monthly repayment plan. */
class BorrowingTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private const ALL = [Permissions::BORROWINGS_VIEW, Permissions::BORROWING_LOANS_CREATE, Permissions::BORROWING_LOANS_UPDATE, Permissions::BORROWING_LOANS_DELETE, Permissions::BORROWING_REPAYMENTS_CREATE, Permissions::BORROWING_REPAYMENTS_DELETE, Permissions::LENDERS_CREATE, Permissions::LENDERS_UPDATE, Permissions::LENDERS_DELETE];

    public function test_lenders_can_be_added_listed_and_searched(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->postJson('/api/v1/lenders', ['name' => 'ABA Bank', 'type' => 'company'])->assertCreated()->assertJsonPath('data.type', 'company');
        $this->postJson('/api/v1/lenders', ['name' => 'Sok Dara', 'type' => 'person', 'phone' => '012345678'])->assertCreated();
        $this->postJson('/api/v1/lenders', ['name' => 'X', 'type' => 'bank'])->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->getJson('/api/v1/lenders')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/lenders?search=dara')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'person');
    }

    public function test_a_loan_is_recorded_with_who_it_is_borrowed_for_and_a_schedule(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->setUpChartOfAccounts();
        $lender = Lender::factory()->create(['name' => 'Sok Dara', 'type' => 'person']);
        $family = ExpenseEntity::factory()->create(['name' => 'Family']);

        $this->postJson('/api/v1/borrowings', ['lender_id' => $lender->id, 'amount' => 1200, 'currency' => 'USD', 'received_on' => '2026-11-01', 'interest_type' => 'none', 'term_months' => 12, 'first_payment_on' => '2026-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('expense_entity_id');

        $response = $this->postJson('/api/v1/borrowings', [
            'lender_id' => $lender->id, 'expense_entity_id' => $family->id, 'amount' => 1200, 'currency' => 'USD',
            'received_on' => '2026-11-01', 'cash_account_id' => $this->cashAccount->id,
            'interest_type' => 'flat', 'interest_rate' => 1, 'interest_period' => 'month',
            'term_months' => 12, 'first_payment_on' => '2026-12-01', 'purpose' => 'House repair',
        ])->assertCreated();

        $response->assertJsonPath('data.borrowed_for.name', 'Family')
            ->assertJsonPath('data.lender.name', 'Sok Dara')
            ->assertJsonPath('data.monthly_payment', 112)
            ->assertJsonPath('data.totals.interest', 144)
            ->assertJsonPath('data.last_payment_on', '2027-11-01')
            ->assertJsonCount(12, 'data.schedule');

        $this->getJson("/api/v1/borrowings?filter[expense_entity_id]={$family->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/borrowings?search=sok')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/borrowings?search=nobody')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_interest_needs_a_rate_and_the_first_payment_comes_after_receiving(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $base = ['lender_id' => Lender::factory()->create()->id, 'expense_entity_id' => ExpenseEntity::factory()->create()->id, 'amount' => 500, 'currency' => 'USD', 'received_on' => '2026-11-01', 'term_months' => 5];

        $this->postJson('/api/v1/borrowings', [...$base, 'interest_type' => 'flat', 'first_payment_on' => '2026-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('interest_rate');
        $this->postJson('/api/v1/borrowings', [...$base, 'interest_type' => 'none', 'first_payment_on' => '2026-10-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('first_payment_on');
    }

    public function test_reducing_balance_gives_equal_payments_and_khr_rounds_to_riel(): void
    {
        $rows = BorrowingSchedule::make(10000, 'USD', 'reducing', 12, 'year', 12, Carbon::parse('2027-01-31'));
        $this->assertSame(888.49, $rows[0]['total']);
        $this->assertSame(100.0, $rows[0]['interest']);
        $this->assertSame(0.0, $rows[11]['balance']);
        $this->assertSame('2027-02-28', $rows[1]['due_on']);
        $this->assertEqualsWithDelta(10000, array_sum(array_column($rows, 'principal')), 0.001);

        $riel = BorrowingSchedule::make(1000000, 'KHR', 'none', 0, 'month', 3, Carbon::parse('2027-01-01'));
        $this->assertSame([333333.0, 333333.0, 333334.0], array_column($riel, 'principal'));
    }

    public function test_a_contract_is_kept_private_and_downloaded_through_the_api(): void
    {
        Storage::fake('local');
        $this->actingAsAdminWithPermissions(self::ALL);
        $loan = Borrowing::factory()->create();

        $this->post("/api/v1/borrowings/{$loan->id}/contract", ['file' => UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_contract', true)->assertJsonPath('data.contract_name', 'contract.pdf');

        Storage::disk('local')->assertExists($loan->fresh()->contract_path);
        $this->get("/api/v1/borrowings/{$loan->id}/contract")->assertOk()->assertDownload('contract.pdf');
    }

    public function test_a_cancelled_loan_cannot_be_edited_and_a_lender_with_loans_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $loan = Borrowing::factory()->create();

        $this->postJson("/api/v1/borrowings/{$loan->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.next_payment', null);
        $this->putJson("/api/v1/borrowings/{$loan->id}", ['amount' => 50])->assertUnprocessable();

        $this->deleteJson("/api/v1/lenders/{$loan->lender_id}")->assertUnprocessable();
    }

    public function test_viewing_needs_borrowings_view_and_changes_need_manage(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXPENSE_VIEW]);
        $this->getJson('/api/v1/borrowings')->assertForbidden();
        $this->getJson('/api/v1/lenders')->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::BORROWINGS_VIEW]);
        $this->getJson('/api/v1/borrowings')->assertOk();
        $this->getJson('/api/v1/expense-entities')->assertOk();
        $this->postJson('/api/v1/lenders', ['name' => 'ABA', 'type' => 'company'])->assertForbidden();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Loan;

use App\Models\Account;
use App\Support\Accounting\AccountType;

/**
 * The ledger accounts the Loan menu posts to. Each is found by its usual
 * code (else by name) and added when missing, since schools set up before
 * the Loan menu don't have them.
 */
final class LoanLedgerAccounts
{
    /** Savings customers keep with the owner: owed back to them. */
    private const CUSTOMER_SAVINGS = ['code' => '2200', 'name' => 'Customer Savings', 'type' => AccountType::LIABILITY, 'parent' => '2000'];

    /** Owe: money lent to customers, still to come back. */
    private const LOANS_RECEIVABLE = ['code' => '1300', 'name' => 'Loans Receivable', 'type' => AccountType::ASSET, 'parent' => '1000'];

    /** Owe: interest customers pay. */
    private const LOAN_INTEREST_INCOME = ['code' => '4950', 'name' => 'Loan Interest Income', 'type' => AccountType::REVENUE, 'parent' => '4000'];

    public function customerSavings(): Account
    {
        return $this->findOrCreate(self::CUSTOMER_SAVINGS);
    }

    public function loansReceivable(): Account
    {
        return $this->findOrCreate(self::LOANS_RECEIVABLE);
    }

    public function loanInterestIncome(): Account
    {
        return $this->findOrCreate(self::LOAN_INTEREST_INCOME);
    }

    /** @param  array{code: string, name: string, type: string, parent: string}  $spec */
    private function findOrCreate(array $spec): Account
    {
        $found = Account::query()->where('code', $spec['code'])->where('type', $spec['type'])->first()
            ?? Account::query()->where('name', $spec['name'])->where('type', $spec['type'])->orderBy('id')->first();
        if ($found) {
            return $found;
        }

        $code = (int) $spec['code'];
        while (Account::query()->where('code', (string) $code)->exists()) {
            $code += 10;
        }

        return Account::query()->create([
            'code' => (string) $code,
            'name' => $spec['name'],
            'type' => $spec['type'],
            'parent_id' => Account::query()->where('code', $spec['parent'])->where('type', $spec['type'])->value('id'),
            'is_bank_or_cash' => false,
        ]);
    }
}

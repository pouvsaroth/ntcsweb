<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * `{PREFIX}-{year}-{6-digit sequence}`, e.g. `EXP-2026-000001` — reuses the
 * exact same `billing_number_sequences` table and locking pattern as
 * BillingNumberGenerator (new `series` values: 'expense', 'transaction',
 * 'transfer'), rather than a parallel table — see that migration's docblock
 * for why one shared table backs every numbered document in this app.
 */
final class AccountingNumberGenerator
{
    public const DEFAULT_EXPENSE_PREFIX = 'EXP';

    public const DEFAULT_TRANSACTION_PREFIX = 'TXN';

    public const DEFAULT_TRANSFER_PREFIX = 'TRF';

    private const PAD_LENGTH = 6;

    public function expensePrefixFor(Tenant $tenant): string
    {
        return $tenant->setting('expense_prefix', self::DEFAULT_EXPENSE_PREFIX);
    }

    public function transactionPrefixFor(Tenant $tenant): string
    {
        return $tenant->setting('transaction_prefix', self::DEFAULT_TRANSACTION_PREFIX);
    }

    public function transferPrefixFor(Tenant $tenant): string
    {
        return $tenant->setting('transfer_prefix', self::DEFAULT_TRANSFER_PREFIX);
    }

    public function nextExpenseNumber(Tenant $tenant): string
    {
        return $this->next($tenant, 'expense', $this->expensePrefixFor($tenant), 'expenses', 'expense_number');
    }

    public function nextTransactionNumber(Tenant $tenant): string
    {
        return $this->next($tenant, 'transaction', $this->transactionPrefixFor($tenant), 'financial_transactions', 'transaction_number');
    }

    /** Transfers are just financial_transactions with type=TRANSFER, but get their own visible number series (TRF-...) for readability. */
    public function nextTransferNumber(Tenant $tenant): string
    {
        return $this->next($tenant, 'transfer', $this->transferPrefixFor($tenant), 'financial_transactions', 'transaction_number');
    }

    private function next(Tenant $tenant, string $series, string $prefix, string $seedTable, string $seedColumn): string
    {
        $year = (int) now()->year;

        return DB::transaction(function () use ($tenant, $series, $prefix, $year, $seedTable, $seedColumn) {
            $this->ensureSequenceRow($tenant, $series, $prefix, $year, $seedTable, $seedColumn);

            $sequence = DB::table('billing_number_sequences')
                ->where('tenant_id', $tenant->getKey())
                ->where('series', $series)
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            DB::table('billing_number_sequences')
                ->where('id', $sequence->id)
                ->update(['next_number' => $sequence->next_number + 1, 'updated_at' => now()]);

            return sprintf('%s-%d-%0'.self::PAD_LENGTH.'d', $prefix, $year, $sequence->next_number);
        });
    }

    private function ensureSequenceRow(Tenant $tenant, string $series, string $prefix, int $year, string $seedTable, string $seedColumn): void
    {
        $exists = DB::table('billing_number_sequences')
            ->where('tenant_id', $tenant->getKey())
            ->where('series', $series)
            ->where('prefix', $prefix)
            ->where('year', $year)
            ->exists();

        if ($exists) {
            return;
        }

        $startingNumber = $this->highestExistingNumber($tenant->getKey(), $prefix, $year, $seedTable, $seedColumn) + 1;

        DB::table('billing_number_sequences')->insertOrIgnore([
            'tenant_id' => $tenant->getKey(),
            'series' => $series,
            'prefix' => $prefix,
            'year' => $year,
            'next_number' => $startingNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function highestExistingNumber(int $tenantId, string $prefix, int $year, string $table, string $column): int
    {
        $like = "{$prefix}-{$year}-%";

        return DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where($column, 'like', $like)
            ->pluck($column)
            ->map(function (string $number) use ($prefix, $year) {
                $suffix = substr($number, strlen($prefix.'-'.$year.'-'));

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;
    }
}

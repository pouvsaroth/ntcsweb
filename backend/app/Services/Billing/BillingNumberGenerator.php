<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * `{PREFIX}-{year}-{6-digit sequence}`, e.g. `INV-2026-000001` — the same
 * concurrency-safe counter pattern as StudentIdGenerator (transaction +
 * `SELECT ... FOR UPDATE` row lock), generalized with a `series` column so
 * one table (`billing_number_sequences`) backs both invoice and payment/
 * receipt numbering without two near-identical tables. Keyed additionally by
 * `year`, so each year's counter restarts at 1 independently — switching
 * years never collides with (and never reuses) the previous year's numbers.
 *
 * Plain DB::table() calls, no Eloquent model — same reasoning as
 * StudentIdGenerator: row locking under concurrency needs this, a jsonb
 * settings read-modify-write cannot provide it safely.
 */
final class BillingNumberGenerator
{
    public const DEFAULT_INVOICE_PREFIX = 'INV';

    public const DEFAULT_RECEIPT_PREFIX = 'RCPT';

    private const PAD_LENGTH = 6;

    public function invoicePrefixFor(Tenant $tenant): string
    {
        return $tenant->setting('invoice_prefix', self::DEFAULT_INVOICE_PREFIX);
    }

    public function receiptPrefixFor(Tenant $tenant): string
    {
        return $tenant->setting('receipt_prefix', self::DEFAULT_RECEIPT_PREFIX);
    }

    public function nextInvoiceNumber(Tenant $tenant): string
    {
        return $this->next($tenant, 'invoice', $this->invoicePrefixFor($tenant), 'invoices', 'invoice_number');
    }

    /** Also the receipt number — see Payment's own docblock for why there is no separate receipts table. */
    public function nextPaymentNumber(Tenant $tenant): string
    {
        return $this->next($tenant, 'payment', $this->receiptPrefixFor($tenant), 'payments', 'payment_number');
    }

    private function next(Tenant $tenant, string $series, string $prefix, string $seedTable, string $seedColumn): string
    {
        $year = (int) now()->year;

        return DB::transaction(function () use ($tenant, $series, $prefix, $year, $seedTable, $seedColumn) {
            $this->ensureSequenceRow($tenant, $series, $prefix, $year, $seedTable, $seedColumn);

            // FOR UPDATE: holds the row lock until this transaction commits,
            // so a second concurrent call blocks here instead of reading the
            // same next_number — see StudentIdGenerator::next() for the full
            // reasoning, identical here.
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

    /**
     * Same insertOrIgnore-then-fall-through race protection as
     * StudentIdGenerator::ensureSequenceRow() — see there for why.
     */
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

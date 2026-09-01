<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * `{PREFIX}-{6-digit sequence}`, e.g. `AST-000001` — reuses the same
 * `billing_number_sequences` table and `SELECT ... FOR UPDATE` locking
 * pattern as BillingNumberGenerator/AccountingNumberGenerator (new
 * `asset` series), but deliberately does NOT key by year the way invoices
 * and expenses do: an asset purchased in 2024 keeps the same number for as
 * long as it exists (possibly a decade), so a yearly-resetting counter
 * would be semantically wrong here. The `year` column is not nullable on
 * the shared table, so `0` is used as a fixed "no year" sentinel — the
 * table/locking mechanism is reused as-is, only the year-keying is opted
 * out of.
 */
final class AssetNumberGenerator
{
    public const DEFAULT_PREFIX = 'AST';

    public const DEFAULT_ISSUE_PREFIX = 'ISS';

    public const DEFAULT_REPAIR_PREFIX = 'REP';

    public const DEFAULT_MAINTENANCE_PREFIX = 'MNT';

    private const NO_YEAR = 0;

    private const PAD_LENGTH = 6;

    public function prefixFor(Tenant $tenant): string
    {
        return $tenant->setting('asset_prefix', self::DEFAULT_PREFIX);
    }

    public function next(Tenant $tenant): string
    {
        $prefix = $this->prefixFor($tenant);

        return DB::transaction(function () use ($tenant, $prefix) {
            $this->ensureSequenceRow($tenant, $prefix);

            $sequence = DB::table('billing_number_sequences')
                ->where('tenant_id', $tenant->getKey())
                ->where('series', 'asset')
                ->where('prefix', $prefix)
                ->where('year', self::NO_YEAR)
                ->lockForUpdate()
                ->first();

            DB::table('billing_number_sequences')
                ->where('id', $sequence->id)
                ->update(['next_number' => $sequence->next_number + 1, 'updated_at' => now()]);

            return sprintf('%s-%0'.self::PAD_LENGTH.'d', $prefix, $sequence->next_number);
        });
    }

    /** `ISS-{year}-######` — issues, unlike the asset itself, are naturally year-scoped events. */
    public function nextIssueNumber(Tenant $tenant): string
    {
        return $this->nextYearKeyed($tenant, 'asset_issue', $tenant->setting('asset_issue_prefix', self::DEFAULT_ISSUE_PREFIX), 'asset_issues', 'issue_number');
    }

    /** `REP-{year}-######`. */
    public function nextRepairNumber(Tenant $tenant): string
    {
        return $this->nextYearKeyed($tenant, 'asset_repair', $tenant->setting('asset_repair_prefix', self::DEFAULT_REPAIR_PREFIX), 'asset_repairs', 'repair_number');
    }

    /** `MNT-{year}-######`. */
    public function nextMaintenanceNumber(Tenant $tenant): string
    {
        return $this->nextYearKeyed($tenant, 'asset_maintenance', $tenant->setting('asset_maintenance_prefix', self::DEFAULT_MAINTENANCE_PREFIX), 'asset_maintenances', 'maintenance_number');
    }

    private function nextYearKeyed(Tenant $tenant, string $series, string $prefix, string $seedTable, string $seedColumn): string
    {
        $year = (int) now()->year;

        return DB::transaction(function () use ($tenant, $series, $prefix, $year, $seedTable, $seedColumn) {
            $exists = DB::table('billing_number_sequences')
                ->where('tenant_id', $tenant->getKey())
                ->where('series', $series)
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->exists();

            if (! $exists) {
                $startingNumber = DB::table($seedTable)
                    ->where('tenant_id', $tenant->getKey())
                    ->where($seedColumn, 'like', "{$prefix}-{$year}-%")
                    ->pluck($seedColumn)
                    ->map(function (string $number) use ($prefix, $year) {
                        $suffix = substr($number, strlen($prefix.'-'.$year.'-'));

                        return ctype_digit($suffix) ? (int) $suffix : 0;
                    })
                    ->max() ?? 0;

                DB::table('billing_number_sequences')->insertOrIgnore([
                    'tenant_id' => $tenant->getKey(),
                    'series' => $series,
                    'prefix' => $prefix,
                    'year' => $year,
                    'next_number' => $startingNumber + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

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

    private function ensureSequenceRow(Tenant $tenant, string $prefix): void
    {
        $exists = DB::table('billing_number_sequences')
            ->where('tenant_id', $tenant->getKey())
            ->where('series', 'asset')
            ->where('prefix', $prefix)
            ->where('year', self::NO_YEAR)
            ->exists();

        if ($exists) {
            return;
        }

        $startingNumber = $this->highestExistingNumber($tenant->getKey(), $prefix) + 1;

        DB::table('billing_number_sequences')->insertOrIgnore([
            'tenant_id' => $tenant->getKey(),
            'series' => 'asset',
            'prefix' => $prefix,
            'year' => self::NO_YEAR,
            'next_number' => $startingNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function highestExistingNumber(int $tenantId, string $prefix): int
    {
        $like = "{$prefix}-%";

        return DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->where('asset_number', 'like', $like)
            ->pluck('asset_number')
            ->map(function (string $number) use ($prefix) {
                $suffix = substr($number, strlen($prefix.'-'));

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;
    }
}

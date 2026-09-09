<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * `{PREFIX}-{4-digit sequence}`, e.g. `NTSS-0001` — see StaffController::store(),
 * the only caller. Same pattern as StudentIdGenerator (see that class's
 * docblock for the full reasoning); the only differences are the default
 * prefix, the sequence table, and the padding width.
 */
class StaffIdGenerator
{
    /**
     * The one place this literal lives — GeneralSettingsController reads it
     * too, so "what does a school get before it ever configures one" is
     * never duplicated.
     */
    public const DEFAULT_PREFIX = 'NTSS';

    private const PAD_LENGTH = 4;

    public function prefixFor(Tenant $tenant): string
    {
        return $tenant->setting('staff_id_prefix', self::DEFAULT_PREFIX);
    }

    public function next(Tenant $tenant): string
    {
        $prefix = $this->prefixFor($tenant);

        return DB::connection('tenant')->transaction(function () use ($tenant, $prefix) {
            $this->ensureSequenceRow($tenant, $prefix);

            // FOR UPDATE: holds the row lock until this transaction commits,
            // so a second concurrent call blocks here instead of reading the
            // same next_number — the actual concurrency guarantee.
            $sequence = DB::connection('tenant')->table('staff_id_sequences')
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            DB::connection('tenant')->table('staff_id_sequences')
                ->where('id', $sequence->id)
                ->update(['next_number' => $sequence->next_number + 1, 'updated_at' => now()]);

            return sprintf('%s-%0'.self::PAD_LENGTH.'d', $prefix, $sequence->next_number);
        });
    }

    /**
     * The first time a (tenant, prefix) pair is used, its counter starts one
     * past the highest employee_code already using that exact prefix —
     * including soft-deleted rows, and including codes entered by hand
     * before this feature existed — so a freshly-seeded counter can never
     * collide with pre-existing data.
     *
     * insertOrIgnore(), not a plain insert: if two requests race to seed the
     * very same brand-new prefix at once, only one row survives the unique
     * constraint on prefix; both callers then fall through to the locked
     * read above using whichever one won, instead of one of them throwing on
     * a duplicate-key error.
     */
    private function ensureSequenceRow(Tenant $tenant, string $prefix): void
    {
        $exists = DB::connection('tenant')->table('staff_id_sequences')
            ->where('prefix', $prefix)
            ->exists();

        if ($exists) {
            return;
        }

        $startingNumber = $this->highestExistingNumber($prefix) + 1;

        DB::connection('tenant')->table('staff_id_sequences')->insertOrIgnore([
            'prefix' => $prefix,
            'next_number' => $startingNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function highestExistingNumber(string $prefix): int
    {
        return DB::connection('tenant')->table('staff')
            ->where('employee_code', 'like', $prefix.'-%')
            ->pluck('employee_code')
            ->map(function (string $code) use ($prefix) {
                $suffix = substr($code, strlen($prefix) + 1);

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;
    }
}

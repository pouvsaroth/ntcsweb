<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * `{PREFIX}-{6-digit sequence}`, e.g. `NTS-000001` — see
 * StudentController::store(), the only caller.
 *
 * The counter lives in `student_id_sequences`, keyed on (tenant_id, prefix),
 * queried with plain DB::table() calls (no Eloquent model) — the same choice
 * PasswordResetService makes for a similarly small, non-domain-object table.
 * It is deliberately NOT part of `tenants.settings` (where the prefix
 * *itself* lives, see prefixFor()): a hot-incrementing counter under
 * concurrent requests needs `SELECT ... FOR UPDATE` row locking, which a
 * jsonb read-modify-write cannot provide safely.
 */
class StudentIdGenerator
{
    /**
     * The one place this literal lives — GeneralSettingsController reads it
     * too, so "what does a school get before it ever configures one" is
     * never duplicated.
     */
    public const DEFAULT_PREFIX = 'NTS';

    private const PAD_LENGTH = 6;

    public function prefixFor(Tenant $tenant): string
    {
        return $tenant->setting('student_id_prefix', self::DEFAULT_PREFIX);
    }

    public function next(Tenant $tenant): string
    {
        $prefix = $this->prefixFor($tenant);

        return DB::transaction(function () use ($tenant, $prefix) {
            $this->ensureSequenceRow($tenant, $prefix);

            // FOR UPDATE: holds the row lock until this transaction commits,
            // so a second concurrent call blocks here instead of reading the
            // same next_number — the actual concurrency guarantee. Laravel
            // nests this transaction as a savepoint when called from inside
            // StudentController::store()'s own transaction, so the lock is
            // held for that whole outer unit of work either way.
            $sequence = DB::table('student_id_sequences')
                ->where('tenant_id', $tenant->getKey())
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            DB::table('student_id_sequences')
                ->where('id', $sequence->id)
                ->update(['next_number' => $sequence->next_number + 1, 'updated_at' => now()]);

            return sprintf('%s-%0'.self::PAD_LENGTH.'d', $prefix, $sequence->next_number);
        });
    }

    /**
     * The first time a (tenant, prefix) pair is used, its counter starts one
     * past the highest student_code already using that exact prefix —
     * including soft-deleted rows, and including codes entered by hand
     * before this feature existed — so a freshly-seeded counter can never
     * collide with pre-existing data.
     *
     * insertOrIgnore(), not a plain insert: if two requests race to seed the
     * very same brand-new prefix at once, only one row survives the unique
     * constraint on (tenant_id, prefix); both callers then fall through to
     * the locked read above using whichever one won, instead of one of them
     * throwing on a duplicate-key error.
     */
    private function ensureSequenceRow(Tenant $tenant, string $prefix): void
    {
        $exists = DB::table('student_id_sequences')
            ->where('tenant_id', $tenant->getKey())
            ->where('prefix', $prefix)
            ->exists();

        if ($exists) {
            return;
        }

        $startingNumber = $this->highestExistingNumber($tenant->getKey(), $prefix) + 1;

        DB::table('student_id_sequences')->insertOrIgnore([
            'tenant_id' => $tenant->getKey(),
            'prefix' => $prefix,
            'next_number' => $startingNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function highestExistingNumber(int $tenantId, string $prefix): int
    {
        return DB::table('students')
            ->where('tenant_id', $tenantId)
            ->where('student_code', 'like', $prefix.'-%')
            ->pluck('student_code')
            ->map(function (string $code) use ($prefix) {
                $suffix = substr($code, strlen($prefix) + 1);

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;
    }
}

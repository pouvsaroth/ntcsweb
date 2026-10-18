<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Models\Enrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The plan's student limit (docs/backoffice/stage-5-billing.md, 5d).
 *
 * An active student is a student with at least one enrollment that is not
 * started, studying or exam-ready. Enrolling a student who is not already
 * active, or moving an enrollment back into one of those statuses, is refused
 * once the school is at its limit. Nothing already enrolled is ever touched:
 * a school over its limit after a downgrade keeps everyone, it just cannot
 * add more.
 *
 * Runs inside the school (the `tenant` connection, row-level security scoped).
 */
final class StudentLimit
{
    public const COUNTED = [Enrollment::STATUS_NOT_STARTED, Enrollment::STATUS_ACTIVE, Enrollment::STATUS_EXAM_READY];

    /** From this share of the limit the owner is warned (5e banner). */
    public const WARN_AT = 0.9;

    public function __construct(
        private readonly SchoolModules $plan,
        private readonly TenantContext $context,
    ) {}

    public function limit(): ?int
    {
        return $this->plan->maxStudents($this->context->id());
    }

    public function activeCount(): int
    {
        return Enrollment::query()->whereIn('status', self::COUNTED)->distinct()->count('student_id');
    }

    /** @return array{active: int, limit: int|null} */
    public function usage(): array
    {
        return ['active' => $this->activeCount(), 'limit' => $this->limit()];
    }

    /**
     * Refuse when $studentId would become a new active student past the limit.
     * $ignoreEnrollment: the enrollment being changed, which does not count
     * as already making the student active.
     */
    public function assertRoomFor(int $studentId, ?int $ignoreEnrollment = null): void
    {
        $limit = $this->limit();
        if ($limit === null) {
            return;
        }

        // Two staff enrolling at once must not both take the last place.
        $db = DB::connection('tenant');
        if ($db->transactionLevel() > 0) {
            $db->select('select pg_advisory_xact_lock(?, ?)', [crc32('student_limit') & 0x7FFFFFFF, (int) $this->context->id()]);
        }

        $alreadyActive = Enrollment::query()
            ->where('student_id', $studentId)
            ->whereIn('status', self::COUNTED)
            ->when($ignoreEnrollment, fn ($q) => $q->whereKeyNot($ignoreEnrollment))
            ->exists();

        if (! $alreadyActive && $this->activeCount() >= $limit) {
            throw ValidationException::withMessages([
                'student_limit' => __('Your plan allows :limit active students. Contact us to upgrade your plan.', ['limit' => number_format($limit)]),
            ]);
        }
    }
}

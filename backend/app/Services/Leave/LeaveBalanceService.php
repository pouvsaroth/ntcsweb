<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Models\Holiday;
use App\Models\LeaveBalanceEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HRM > Leave Management's arithmetic — how many working days a staff
 * member's leave takes, what a policy gives them in a year, and what's left.
 *
 * - A working day is one their work schedule (their own, else the default)
 *   has a shift on, and that isn't a holiday. With no work schedule set up at
 *   all, Monday to Saturday count.
 * - Days are in half days: a one-day request may be a morning or afternoon.
 * - A request stays inside one calendar year, so each year's balance is just
 *   that year's: entitlement + carried forward + adjustments − approved −
 *   pending = available (see balance()).
 *   Pending requests count too, so two requests waiting at once can't both
 *   spend the same days.
 */
final class LeaveBalanceService
{
    /** @var array<int, WorkSchedule|null> */
    private array $schedules = [];

    /**
     * The working dates between two days (inclusive) for this staff member.
     *
     * @return list<string> Y-m-d
     */
    public function workingDates(Staff $staff, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $schedule = $this->schedules[$staff->id] ??= WorkSchedule::forStaff($staff);
        $holidays = Holiday::query()
            ->whereDate('end_date', '>=', $from->toDateString())
            ->whereDate('start_date', '<=', $to->toDateString())
            ->get();

        $dates = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $works = $schedule !== null ? $schedule->shiftIdOn($day) !== null : ! $day->isSunday();
            $holiday = $holidays->contains(fn (Holiday $h) => $h->start_date->toDateString() <= $date && $h->end_date->toDateString() >= $date);

            if ($works && ! $holiday) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /** Working days a request takes — a half day is 0.5. */
    public function countDays(Staff $staff, CarbonImmutable $from, CarbonImmutable $to, string $dayPart = LeaveRequest::DAY_FULL): float
    {
        $days = count($this->workingDates($staff, $from, $to));

        return $dayPart === LeaveRequest::DAY_FULL ? (float) $days : $days * 0.5;
    }

    /** The first day this policy lets them take leave — hire date + the months of service it asks for. */
    public function eligibleFrom(Staff $staff, LeavePolicy $policy): ?CarbonImmutable
    {
        if ($staff->hire_date === null) {
            return null;
        }

        return CarbonImmutable::parse($staff->hire_date->toDateString())->addMonthsNoOverflow($policy->min_service_months);
    }

    /**
     * Days the policy gives in a year: the yearly days, prorated from the
     * hire date in the year they join, plus long-service days (capped by
     * the policy's most days a year), to the nearest half day. Without a hire
     * date on file they get the full yearly days and no long-service days.
     */
    public function entitlement(Staff $staff, LeavePolicy $policy, int $year): float
    {
        $yearStart = CarbonImmutable::create($year, 1, 1);
        $yearEnd = CarbonImmutable::create($year, 12, 31);

        if ($staff->hire_date === null) {
            return $policy->days_per_year;
        }

        $hired = CarbonImmutable::parse($staff->hire_date->toDateString());
        if ($hired->greaterThan($yearEnd)) {
            return 0.0;
        }

        $days = $policy->days_per_year;
        if ($policy->prorate_first_year && $hired->greaterThan($yearStart)) {
            $days *= ($hired->diffInDays($yearEnd) + 1) / ($yearStart->diffInDays($yearEnd) + 1);
        }

        if ($policy->service_bonus_every_years && $policy->service_bonus_days > 0) {
            $years = (int) floor($hired->diffInYears($yearEnd));
            $days += intdiv($years, $policy->service_bonus_every_years) * $policy->service_bonus_days;
            if ($policy->max_days_per_year !== null) {
                $days = min($days, max($policy->max_days_per_year, $policy->days_per_year));
            }
        }

        return round($days * 2) / 2;
    }

    /**
     * A staff member's balance of one leave type for a year — null when no
     * active policy applies (that type is then not limited by a balance).
     *
     * total = entitlement + carried forward + adjustments; available = total
     * − used − pending. Carried days only cover leave up to their expiry:
     * as of a later date (`$asOf`, default today, never past the year's end)
     * whatever of them wasn't taken by then has lapsed.
     *
     * @return array{policy_id: int, entitlement: float, carried_forward: float, carried_expires_on: string|null, carried_lapsed: float, adjustments: float, total: float, used: float, pending: float, available: float}|null
     */
    public function balance(Staff $staff, LeaveType $type, int $year, ?CarbonImmutable $asOf = null): ?array
    {
        $policy = LeavePolicy::forStaff($staff, $type->id);
        if ($policy === null) {
            return null;
        }

        $asOf = ($asOf ?? CarbonImmutable::today())->min(CarbonImmutable::create($year, 12, 31));
        $entitlement = $this->entitlement($staff, $policy, $year);

        $entries = LeaveBalanceEntry::query()
            ->where('staff_id', $staff->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->get();
        $adjustments = (float) $entries->where('kind', LeaveBalanceEntry::KIND_ADJUSTMENT)->sum('days');
        $carry = $entries->firstWhere('kind', LeaveBalanceEntry::KIND_CARRY_FORWARD);
        $carried = (float) ($carry?->days ?? 0);

        $requests = LeaveRequest::query()
            ->where('staff_id', $staff->id)
            ->where('leave_type_id', $type->id)
            ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_PENDING])
            ->whereYear('from_date', $year)
            ->get(['status', 'days', 'from_date']);

        $used = (float) $requests->where('status', LeaveRequest::STATUS_APPROVED)->sum('days');
        $pending = (float) $requests->where('status', LeaveRequest::STATUS_PENDING)->sum('days');

        // Leave up to the expiry spends the carried days first.
        $carriedUsable = $carried;
        if ($carry?->expires_on !== null && $asOf->greaterThan($carry->expires_on)) {
            $takenByExpiry = (float) $requests->filter(fn (LeaveRequest $r) => $r->from_date->lessThanOrEqualTo($carry->expires_on))->sum('days');
            $carriedUsable = min($carried, $takenByExpiry);
        }

        $total = $entitlement + $carriedUsable + $adjustments;

        return [
            'policy_id' => $policy->id,
            'entitlement' => $entitlement,
            'carried_forward' => $carried,
            'carried_expires_on' => $carry?->expires_on?->toDateString(),
            'carried_lapsed' => $carried - $carriedUsable,
            'adjustments' => $adjustments,
            'total' => $total,
            'used' => $used,
            'pending' => $pending,
            'available' => $total - $used - $pending,
        ];
    }

    /**
     * Year-end carry forward: each working staff member's unused days of
     * each leave type in `$fromYear` move into the next year, capped by
     * their policy's "most days carried" (to the half day below) and
     * expiring after its months, if set. Pending requests count as taken —
     * decide them first. Running it again replaces the earlier result.
     *
     * @return array{entries: int, days: float, pending_requests: int}
     */
    public function carryForward(int $fromYear, ?User $by = null): array
    {
        $toYear = $fromYear + 1;
        $yearEnd = CarbonImmutable::create($fromYear, 12, 31);
        $types = LeaveType::query()->where('is_active', true)->get();
        $entries = 0;
        $days = 0.0;

        DB::connection('tenant')->transaction(function () use ($fromYear, $toYear, $yearEnd, $types, $by, &$entries, &$days) {
            LeaveBalanceEntry::query()->where('year', $toYear)->where('kind', LeaveBalanceEntry::KIND_CARRY_FORWARD)->delete();

            Staff::query()->whereIn('status', Staff::STATUSES_WORKING)->each(function (Staff $staff) use ($fromYear, $toYear, $yearEnd, $types, $by, &$entries, &$days) {
                foreach ($types as $type) {
                    $policy = LeavePolicy::forStaff($staff, $type->id);
                    if ($policy === null || $policy->max_carry_forward_days <= 0) {
                        continue;
                    }

                    $balance = $this->balance($staff, $type, $fromYear, $yearEnd);
                    $carry = floor(min(max(0, $balance['available'] ?? 0), $policy->max_carry_forward_days) * 2) / 2;
                    if ($carry <= 0) {
                        continue;
                    }

                    LeaveBalanceEntry::query()->create([
                        'staff_id' => $staff->id,
                        'leave_type_id' => $type->id,
                        'year' => $toYear,
                        'kind' => LeaveBalanceEntry::KIND_CARRY_FORWARD,
                        'days' => $carry,
                        'expires_on' => $policy->carry_forward_expiry_months
                            ? CarbonImmutable::create($toYear, 1, 1)->addMonthsNoOverflow($policy->carry_forward_expiry_months)->subDay()->toDateString()
                            : null,
                        'note' => "Unused days from {$fromYear}",
                        'created_by' => $by?->getKey(),
                    ]);
                    $entries++;
                    $days += $carry;
                }
            });
        });

        $pending = LeaveRequest::query()->whereNotNull('staff_id')->whereNotNull('leave_type_id')
            ->where('status', LeaveRequest::STATUS_PENDING)->whereYear('from_date', $fromYear)->count();

        return ['entries' => $entries, 'days' => $days, 'pending_requests' => $pending];
    }

    /**
     * Every active leave type this staff member may take (gender allowing),
     * each with this year's balance — for the request form.
     *
     * @return Collection<int, array{type: LeaveType, balance: array<string, float|int>|null}>
     */
    public function typesFor(Staff $staff, int $year): Collection
    {
        return LeaveType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (LeaveType $type) => $this->genderAllows($staff, $type))
            ->values()
            ->map(fn (LeaveType $type) => ['type' => $type, 'balance' => $this->balance($staff, $type, $year)]);
    }

    /**
     * Checks a staff member's leave request against its type and policy and
     * works out its days. `$byHr`: HR filing it on their behalf — the notice
     * period doesn't apply (it's often sick leave after the fact).
     *
     * @param  array{leave_type_id?: int|null, from_date: string, to_date: string, day_part?: string|null, has_attachment?: bool}  $data
     * @return array{leave_type_id: int|null, day_part: string|null, days: float|null}
     *
     * @throws ValidationException
     */
    public function check(Staff $staff, array $data, bool $byHr = false): array
    {
        $typeId = $data['leave_type_id'] ?? null;

        if ($typeId === null) {
            // Only while the school hasn't set up any leave types yet.
            if (LeaveType::query()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['leave_type_id' => 'Choose a leave type.']);
            }

            return ['leave_type_id' => null, 'day_part' => null, 'days' => null];
        }

        $type = LeaveType::query()->find($typeId);
        if ($type === null || ! $type->is_active || ! $this->genderAllows($staff, $type)) {
            throw ValidationException::withMessages(['leave_type_id' => 'This leave type is not available to you.']);
        }

        $from = CarbonImmutable::parse($data['from_date'])->startOfDay();
        $to = CarbonImmutable::parse($data['to_date'])->startOfDay();
        $dayPart = $data['day_part'] ?? LeaveRequest::DAY_FULL;

        if ($from->year !== $to->year) {
            throw ValidationException::withMessages(['to_date' => 'A leave request must stay within one year. Make one request up to 31 December and another from 1 January.']);
        }

        if ($dayPart !== LeaveRequest::DAY_FULL) {
            if (! $type->allow_half_day) {
                throw ValidationException::withMessages(['day_part' => 'This leave type can only be taken in whole days.']);
            }
            if (! $from->equalTo($to)) {
                throw ValidationException::withMessages(['day_part' => 'A half day must be a single date.']);
            }
        }

        if ($type->requires_attachment && ! ($data['has_attachment'] ?? false)) {
            throw ValidationException::withMessages(['attachments' => 'This leave type needs a supporting file (e.g. a medical certificate).']);
        }

        $days = $this->countDays($staff, $from, $to, $dayPart);
        if ($days <= 0) {
            throw ValidationException::withMessages(['from_date' => 'These dates are all days off or holidays — there is nothing to take leave from.']);
        }

        $overlaps = LeaveRequest::query()
            ->where('staff_id', $staff->id)
            ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_PENDING])
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->get()
            // Two halves of the same day (morning + afternoon) don't clash.
            ->reject(fn (LeaveRequest $other) => $dayPart !== LeaveRequest::DAY_FULL
                && in_array($other->day_part, [LeaveRequest::DAY_MORNING, LeaveRequest::DAY_AFTERNOON], true)
                && $other->day_part !== $dayPart)
            ->isNotEmpty();
        if ($overlaps) {
            throw ValidationException::withMessages(['from_date' => 'There is already a leave request for some of these dates.']);
        }

        $policy = LeavePolicy::forStaff($staff, $type->id);
        if ($policy !== null) {
            $eligible = $this->eligibleFrom($staff, $policy);
            if ($eligible !== null && $from->lessThan($eligible)) {
                throw ValidationException::withMessages(['from_date' => "This leave can be taken from {$eligible->format('d-m-Y')}, after {$policy->min_service_months} months of service."]);
            }

            if ($policy->max_consecutive_days !== null && $days > $policy->max_consecutive_days) {
                throw ValidationException::withMessages(['to_date' => "At most {$policy->max_consecutive_days} days of this leave can be taken in one request."]);
            }

            if (! $byHr && $policy->min_notice_days > 0) {
                $today = CarbonImmutable::today();
                if ($today->diffInDays($from, false) < $policy->min_notice_days) {
                    throw ValidationException::withMessages(['from_date' => "This leave needs {$policy->min_notice_days} days' notice."]);
                }
            }

            // As of the leave's own date — carried days that expire before it can't pay for it.
            $balance = $this->balance($staff, $type, $from->year, $from);
            if ($balance !== null && $days > $balance['available']) {
                $left = self::format(max(0, $balance['available']));
                throw ValidationException::withMessages(['to_date' => "Not enough {$type->name} left for {$from->year}: this takes ".self::format($days)." days, {$left} left."]);
            }
        }

        return ['leave_type_id' => $type->id, 'day_part' => $dayPart, 'days' => $days];
    }

    private function genderAllows(Staff $staff, LeaveType $type): bool
    {
        if ($type->gender === null) {
            return true;
        }

        $gender = strtolower((string) $staff->gender);
        $gender = ['m' => 'male', 'f' => 'female'][$gender] ?? $gender;

        return $gender === $type->gender;
    }

    private static function format(float $days): string
    {
        return fmod($days, 1.0) === 0.0 ? (string) (int) $days : number_format($days, 1);
    }
}

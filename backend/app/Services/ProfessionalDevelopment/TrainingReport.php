<?php

declare(strict_types=1);

namespace App\Services\ProfessionalDevelopment;

use App\Models\ClassroomObservation;
use App\Models\CoachingSession;
use App\Models\DevelopmentPlan;
use App\Models\DevelopmentPlanGoal;
use App\Models\Position;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\Training;
use App\Models\TrainingParticipant;
use App\Services\Billing\CurrencyConversionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * HRM > Professional Development > Training reports
 * (docs/hrm/professional-development.md): for a date range (trainings by
 * start date), the totals, a month-by-month and category breakdown, and one
 * row per staff member — including those with no training, so gaps show.
 * Hours count only for people marked attended on a completed training.
 */
final class TrainingReport
{
    public function __construct(
        private readonly CurrencyConversionService $currency,
        private readonly TenantContext $context,
    ) {}

    /** A training's cost in `$to` (default the school's currency), converted at the rate on its start date. */
    private function cost(Training $t, ?string $to = null): float
    {
        $school = $this->context->getOrFail()->default_currency;
        $to ??= $school;
        $amount = (float) ($t->cost ?? 0);
        if ($amount === 0.0 || ($t->currency ?: $school) === $to) {
            return $amount;
        }

        return $this->currency->convert($amount, $t->currency ?: $school, $to, $this->currency->rateForDate($t->start_date));
    }

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = [$from->toDateString(), $to->toDateString()];

        $trainings = Training::query()->whereBetween('start_date', $range)
            ->get(['id', 'title', 'category', 'start_date', 'hours', 'cost', 'currency', 'status']);
        $completedIds = $trainings->where('status', 'completed')->pluck('id')->all();

        /** @var Collection<int, TrainingParticipant> $participants */
        $participants = TrainingParticipant::query()->whereIn('training_id', $trainings->pluck('id'))
            ->get(['id', 'training_id', 'staff_id', 'attendance', 'result', 'certificate_issued_on']);
        $byTraining = $trainings->keyBy('id');
        $attended = $participants->filter(fn (TrainingParticipant $p) => $p->attendance === 'attended' && in_array($p->training_id, $completedIds, true));
        $onCompleted = $participants->filter(fn (TrainingParticipant $p) => in_array($p->training_id, $completedIds, true));
        $hoursOf = fn (TrainingParticipant $p) => (float) ($byTraining->get($p->training_id)?->hours ?? 0);

        $certificates = TrainingParticipant::query()->whereBetween('certificate_issued_on', $range)->get(['staff_id']);
        $observations = ClassroomObservation::query()->where('status', 'completed')->whereBetween('observed_on', $range)
            ->get(['staff_id', 'average_rating']);

        $totals = [
            'trainings' => $trainings->where('status', '!=', 'cancelled')->count(),
            'completed' => count($completedIds),
            'cancelled' => $trainings->where('status', 'cancelled')->count(),
            'assigned' => $onCompleted->count(),
            'attended' => $attended->count(),
            'attendance_rate' => $onCompleted->count() > 0 ? (int) round($attended->count() * 100 / $onCompleted->count()) : null,
            'staff_trained' => $attended->pluck('staff_id')->unique()->count(),
            'hours' => round($attended->sum($hoursOf), 1),
            'cost' => round((float) $trainings->where('status', 'completed')->sum(fn (Training $t) => $this->cost($t)), 2),
            'cost_usd' => round((float) $trainings->where('status', 'completed')->sum(fn (Training $t) => $this->cost($t, Tenant::CURRENCY_USD)), 2),
            'cost_khr' => round((float) $trainings->where('status', 'completed')->sum(fn (Training $t) => $this->cost($t, Tenant::CURRENCY_KHR))),
            'certificates' => $certificates->count(),
            'observations' => $observations->count(),
            'average_rating' => $observations->count() > 0 ? round((float) $observations->avg(fn ($o) => (float) $o->average_rating), 2) : null,
            'coaching_sessions' => CoachingSession::query()->whereBetween('session_date', $range)->count(),
            'active_plans' => DevelopmentPlan::query()->where('status', 'active')->count(),
            'plan_goals_done' => DevelopmentPlanGoal::query()->where('status', 'done')
                ->whereHas('plan', fn ($q) => $q->whereIn('status', ['active', 'completed']))->count(),
        ];

        return [
            // Costs are converted to this currency (trainings can be in USD or KHR).
            'currency' => $this->context->getOrFail()->default_currency,
            'from' => $range[0],
            'to' => $range[1],
            'totals' => $totals,
            'by_month' => $this->byMonth($from, $to, $trainings, $attended, $hoursOf),
            'by_category' => $this->byCategory($trainings, $attended, $hoursOf),
            'by_staff' => $this->byStaff($attended, $hoursOf, $byTraining, $certificates, $observations),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function byMonth(CarbonImmutable $from, CarbonImmutable $to, Collection $trainings, Collection $attended, \Closure $hoursOf): array
    {
        $months = [];
        for ($m = $from->startOfMonth(); $m->lte($to) && count($months) < 36; $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'trainings' => 0, 'attended' => 0, 'hours' => 0.0];
        }
        foreach ($trainings->where('status', '!=', 'cancelled') as $t) {
            $key = $t->start_date->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['trainings']++;
            }
        }
        $starts = $trainings->mapWithKeys(fn (Training $t) => [$t->id => $t->start_date->format('Y-m')]);
        foreach ($attended as $p) {
            $key = $starts[$p->training_id] ?? null;
            if ($key !== null && isset($months[$key])) {
                $months[$key]['attended']++;
                $months[$key]['hours'] += $hoursOf($p);
            }
        }

        return array_values(array_map(fn ($m) => [...$m, 'hours' => round($m['hours'], 1)], $months));
    }

    /** @return list<array<string, mixed>> */
    private function byCategory(Collection $trainings, Collection $attended, \Closure $hoursOf): array
    {
        $rows = [];
        foreach ($trainings->where('status', '!=', 'cancelled') as $t) {
            $key = $t->category ?: '';
            $rows[$key] ??= ['category' => $t->category, 'trainings' => 0, 'attended' => 0, 'hours' => 0.0, 'cost' => 0.0, 'cost_usd' => 0.0, 'cost_khr' => 0.0];
            $rows[$key]['trainings']++;
            if ($t->status === 'completed') {
                $rows[$key]['cost'] += $this->cost($t);
                $rows[$key]['cost_usd'] += $this->cost($t, Tenant::CURRENCY_USD);
                $rows[$key]['cost_khr'] += $this->cost($t, Tenant::CURRENCY_KHR);
            }
        }
        $categoryOf = $trainings->mapWithKeys(fn (Training $t) => [$t->id => $t->category ?: '']);
        foreach ($attended as $p) {
            $key = $categoryOf[$p->training_id] ?? '';
            if (isset($rows[$key])) {
                $rows[$key]['attended']++;
                $rows[$key]['hours'] += $hoursOf($p);
            }
        }

        return collect($rows)->map(fn ($r) => [...$r, 'hours' => round($r['hours'], 1), 'cost' => round($r['cost'], 2), 'cost_usd' => round($r['cost_usd'], 2), 'cost_khr' => round($r['cost_khr'])])
            ->sortByDesc('hours')->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function byStaff(Collection $attended, \Closure $hoursOf, Collection $byTraining, Collection $certificates, Collection $observations): array
    {
        $ids = $attended->pluck('staff_id')->merge($certificates->pluck('staff_id'))->merge($observations->pluck('staff_id'))->unique()->all();
        $staff = Staff::query()->with('department:id,name')
            // Current staff, plus anyone (even since left) who trained in the range.
            ->where(fn ($q) => $q->where(fn ($a) => $a->active()->whereNull('deleted_at'))->orWhereIn('id', $ids))
            ->withTrashed()
            ->get(['id', 'first_name', 'last_name', 'employee_code', 'position_id', 'department_id', 'deleted_at']);
        $teacherPositions = Position::teacherPositionIds();

        $attendedBy = $attended->groupBy('staff_id');
        $certsBy = $certificates->countBy('staff_id');
        $obsBy = $observations->groupBy('staff_id');

        return $staff->map(function (Staff $s) use ($attendedBy, $certsBy, $obsBy, $hoursOf, $byTraining, $teacherPositions) {
            $mine = $attendedBy->get($s->id, collect());
            $obs = $obsBy->get($s->id, collect());
            $last = $mine->map(fn ($p) => $byTraining->get($p->training_id)?->start_date)->filter()->max();

            return [
                'staff_id' => $s->id,
                'name' => $s->fullName(),
                'employee_code' => $s->employee_code,
                'department' => $s->department?->name,
                'is_teacher' => in_array($s->position_id, $teacherPositions, true),
                'trainings' => $mine->count(),
                'hours' => round($mine->sum($hoursOf), 1),
                'certificates' => (int) ($certsBy[$s->id] ?? 0),
                'last_training' => $last?->toDateString(),
                'observations' => $obs->count(),
                'average_rating' => $obs->count() > 0 ? round((float) $obs->avg(fn ($o) => (float) $o->average_rating), 2) : null,
            ];
        })->sortBy([['hours', 'desc'], ['name', 'asc']])->values()->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollSetting;
use App\Models\SocialSecurityScheme;
use App\Models\StaffPayrollProfile;
use App\Models\TaxBracket;
use App\Models\Tenant;
use App\Services\Billing\CurrencyConversionService;
use Illuminate\Support\Collection;

/**
 * HRM > Payroll's rules as arithmetic — a monthly salary's daily and hourly
 * rate, Tax on Salary and NSSF. Tax and NSSF are set in riel, so a USD
 * salary is converted at the school's rate for the date (Settings > Currency
 * rates) and the result converted back.
 */
final class PayrollRules
{
    private ?PayrollSetting $settings = null;

    /** @var Collection<int, TaxBracket>|null */
    private ?Collection $brackets = null;

    /** @var Collection<int, SocialSecurityScheme>|null */
    private ?Collection $schemes = null;

    public function __construct(
        private readonly CurrencyConversionService $currency,
    ) {}

    public function settings(): PayrollSetting
    {
        return $this->settings ??= PayrollSetting::current();
    }

    /** Forget cached rules — after they were changed. */
    public function refresh(): void
    {
        $this->settings = null;
        $this->brackets = null;
        $this->schemes = null;
    }

    public function dailyRate(float $monthly): float
    {
        $days = $this->settings()->working_days_per_month;

        return $days > 0 ? $monthly / $days : 0.0;
    }

    public function hourlyRate(float $monthly): float
    {
        $hours = $this->settings()->hours_per_day;

        return $hours > 0 ? $this->dailyRate($monthly) / $hours : 0.0;
    }

    /** KHR per USD on this date, or null when the school has entered no rate. */
    public function khrPerUsd(string $date): ?float
    {
        return $this->currency->rateForDate($date);
    }

    public function toKhr(float $amount, string $currency, ?float $khrPerUsd): float
    {
        return $this->currency->convert($amount, $currency, Tenant::CURRENCY_KHR, $khrPerUsd);
    }

    public function fromKhr(float $amount, string $currency, ?float $khrPerUsd): float
    {
        return $this->currency->convert($amount, Tenant::CURRENCY_KHR, $currency, $khrPerUsd);
    }

    /** @return Collection<int, TaxBracket> lowest first */
    public function brackets(): Collection
    {
        return $this->brackets ??= TaxBracket::query()->orderBy('min_amount')->get();
    }

    /** @return Collection<int, SocialSecurityScheme> */
    public function schemes(): Collection
    {
        return $this->schemes ??= SocialSecurityScheme::query()->where('is_active', true)->orderBy('id')->get();
    }

    /**
     * Monthly Tax on Salary on `$taxable` riel. A resident gets the spouse
     * and child allowances off first, then each bracket's rate on the part
     * of what's left that falls in it; a non-resident pays the flat rate on
     * all of it.
     *
     * @return array{taxable: float, allowances: float, base: float, tax: float}
     */
    public function tax(float $taxable, StaffPayrollProfile $profile): array
    {
        $settings = $this->settings();
        $taxable = max($taxable, 0.0);

        if (! $profile->tax_resident) {
            return [
                'taxable' => round($taxable),
                'allowances' => 0.0,
                'base' => round($taxable),
                'tax' => round($taxable * $settings->tax_non_resident_rate / 100),
            ];
        }

        $allowances = ($profile->spouse_dependent ? $settings->tax_spouse_allowance : 0.0)
            + $profile->child_dependents * $settings->tax_child_allowance;
        $base = max($taxable - $allowances, 0.0);

        $tax = 0.0;
        foreach ($this->brackets() as $bracket) {
            if ($base <= $bracket->min_amount) {
                continue;
            }
            $top = $bracket->max_amount !== null ? min($base, $bracket->max_amount) : $base;
            $tax += ($top - $bracket->min_amount) * $bracket->rate / 100;
        }

        return ['taxable' => round($taxable), 'allowances' => round($allowances), 'base' => round($base), 'tax' => round($tax)];
    }

    /**
     * NSSF on a monthly wage of `$wage` riel — each active scheme's staff and
     * school share of the wage clamped to its floor/ceiling. Nothing for a
     * staff member not enrolled.
     *
     * @return array{lines: list<array{code: string, name: string, base: float, employee: float, employer: float, reduces_taxable: bool}>, employee: float, employer: float, reduces_taxable: float}
     */
    public function socialSecurity(float $wage, bool $enrolled): array
    {
        $lines = [];
        if ($enrolled && $wage > 0) {
            foreach ($this->schemes() as $scheme) {
                $base = max($wage, $scheme->min_wage);
                if ($scheme->max_wage !== null) {
                    $base = min($base, $scheme->max_wage);
                }
                $lines[] = [
                    'code' => $scheme->code,
                    'name' => $scheme->name,
                    'base' => round($base),
                    'employee' => round($base * $scheme->employee_rate / 100),
                    'employer' => round($base * $scheme->employer_rate / 100),
                    'reduces_taxable' => $scheme->reduces_taxable,
                ];
            }
        }

        $lines = collect($lines);

        return [
            'lines' => $lines->all(),
            'employee' => (float) $lines->sum('employee'),
            'employer' => (float) $lines->sum('employer'),
            // The staff share that comes off before Tax on Salary.
            'reduces_taxable' => (float) $lines->where('reduces_taxable', true)->sum('employee'),
        ];
    }
}

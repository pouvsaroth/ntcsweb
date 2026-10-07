<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PayrollSetting;
use App\Models\SocialSecurityScheme;
use App\Models\StaffPayrollProfile;
use App\Models\TaxBracket;
use App\Models\Tenant;
use App\Services\Payroll\PayrollRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * HRM > Payroll's rules — the settings row (Overtime / Attendance deduction /
 * Tax tabs), Tax on Salary brackets, NSSF schemes, and a calculator that
 * shows what a salary pays in NSSF and tax.
 */
final class PayrollRulesController extends Controller
{
    public function __construct(
        private readonly PayrollRules $rules,
    ) {}

    public function show(): JsonResponse
    {
        $this->authorize('viewAny', PayrollSetting::class);

        return ApiResponse::success($this->payload());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $settings = PayrollSetting::current();
        $this->authorize('update', $settings);

        $settings->update($request->validate([
            'working_days_per_month' => ['sometimes', 'numeric', 'min:1', 'max:31'],
            'hours_per_day' => ['sometimes', 'numeric', 'min:1', 'max:24'],
            'overtime_normal_rate' => ['sometimes', 'numeric', 'min:1', 'max:5'],
            'overtime_rest_day_rate' => ['sometimes', 'numeric', 'min:1', 'max:5'],
            'overtime_holiday_rate' => ['sometimes', 'numeric', 'min:1', 'max:5'],
            'deduct_absence' => ['sometimes', 'boolean'],
            'deduct_unpaid_leave' => ['sometimes', 'boolean'],
            'late_deduction_mode' => ['sometimes', Rule::in(PayrollSetting::LATE_MODES)],
            'late_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'late_amount_usd' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'late_amount_khr' => ['sometimes', 'numeric', 'min:0', 'max:1000000000'],
            'deduct_early_leave' => ['sometimes', 'boolean'],
            'tax_spouse_allowance' => ['sometimes', 'numeric', 'min:0', 'max:1000000000'],
            'tax_child_allowance' => ['sometimes', 'numeric', 'min:0', 'max:1000000000'],
            'tax_non_resident_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]));

        $this->rules->refresh();

        return ApiResponse::success($this->payload());
    }

    /** Replaces the whole bracket list — lowest first, each starting where the one before ends, only the last open-ended. */
    public function replaceBrackets(Request $request): JsonResponse
    {
        $this->authorize('update', PayrollSetting::current());

        $data = $request->validate([
            'brackets' => ['required', 'array', 'min:1', 'max:20'],
            'brackets.*.min_amount' => ['required', 'numeric', 'min:0'],
            'brackets.*.max_amount' => ['nullable', 'numeric'],
            'brackets.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $brackets = collect($data['brackets'])->sortBy('min_amount')->values();
        $errors = [];
        foreach ($brackets as $index => $bracket) {
            $last = $index === $brackets->count() - 1;
            if ($bracket['max_amount'] !== null && (float) $bracket['max_amount'] <= (float) $bracket['min_amount']) {
                $errors["brackets.{$index}.max_amount"] = ['The top must be more than the start.'];
            }
            if ($index === 0 && (float) $bracket['min_amount'] !== 0.0) {
                $errors["brackets.{$index}.min_amount"] = ['The first bracket must start at 0.'];
            }
            if (! $last && $bracket['max_amount'] === null) {
                $errors["brackets.{$index}.max_amount"] = ['Only the last bracket can have no top.'];
            }
            if (! $last && $bracket['max_amount'] !== null && (float) $bracket['max_amount'] !== (float) $brackets[$index + 1]['min_amount']) {
                $errors["brackets.{$index}.max_amount"] = ['Each bracket must end where the next one starts.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::connection('tenant')->transaction(function () use ($brackets) {
            TaxBracket::query()->delete();
            foreach ($brackets as $bracket) {
                TaxBracket::query()->create($bracket);
            }
        });

        $this->rules->refresh();

        return ApiResponse::success($this->payload());
    }

    public function storeScheme(Request $request): JsonResponse
    {
        $this->authorize('create', SocialSecurityScheme::class);

        SocialSecurityScheme::query()->create($this->validateScheme($request, null));
        $this->rules->refresh();

        return ApiResponse::created($this->payload());
    }

    public function updateScheme(Request $request, SocialSecurityScheme $socialSecurityScheme): JsonResponse
    {
        $this->authorize('update', $socialSecurityScheme);

        $socialSecurityScheme->update($this->validateScheme($request, $socialSecurityScheme));
        $this->rules->refresh();

        return ApiResponse::success($this->payload());
    }

    public function destroyScheme(SocialSecurityScheme $socialSecurityScheme): JsonResponse
    {
        $this->authorize('delete', $socialSecurityScheme);

        $socialSecurityScheme->delete();
        $this->rules->refresh();

        return ApiResponse::success($this->payload());
    }

    /**
     * What a monthly salary pays: NSSF on the salary, then Tax on Salary on
     * the salary less the NSSF share that reduces it — in riel, and back in
     * the salary's currency.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollSetting::class);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['required', Rule::in([Tenant::CURRENCY_USD, Tenant::CURRENCY_KHR])],
            'tax_resident' => ['sometimes', 'boolean'],
            'spouse_dependent' => ['sometimes', 'boolean'],
            'child_dependents' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'social_security_enrolled' => ['sometimes', 'boolean'],
        ]);

        $profile = new StaffPayrollProfile([
            'tax_resident' => $request->boolean('tax_resident', true),
            'spouse_dependent' => $request->boolean('spouse_dependent'),
            'child_dependents' => (int) ($data['child_dependents'] ?? 0),
            'social_security_enrolled' => $request->boolean('social_security_enrolled', true),
        ]);

        $rate = $this->rules->khrPerUsd(now()->toDateString());
        $wage = $this->rules->toKhr((float) $data['amount'], $data['currency'], $rate);
        $nssf = $this->rules->socialSecurity($wage, $profile->social_security_enrolled);
        $tax = $this->rules->tax($wage - $nssf['reduces_taxable'], $profile);
        $back = fn (float $khr) => $data['currency'] === Tenant::CURRENCY_KHR ? round($khr) : round($this->rules->fromKhr($khr, $data['currency'], $rate), 2);

        return ApiResponse::success([
            'khr_per_usd' => $rate,
            'needs_rate' => $data['currency'] === Tenant::CURRENCY_USD && $rate === null,
            'wage_khr' => round($wage),
            'social_security' => $nssf,
            'tax' => $tax,
            'in_currency' => [
                'currency' => $data['currency'],
                'social_security_employee' => $back($nssf['employee']),
                'social_security_employer' => $back($nssf['employer']),
                'tax' => $back($tax['tax']),
                'net' => $back($wage - $nssf['employee'] - $tax['tax']),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateScheme(Request $request, ?SocialSecurityScheme $scheme): array
    {
        $required = $scheme === null ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$required, 'string', 'max:20', Rule::unique('tenant.social_security_schemes', 'code')->ignore($scheme?->id)],
            'name' => [$required, 'string', 'max:255'],
            'employee_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'employer_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'min_wage' => ['sometimes', 'numeric', 'min:0'],
            'max_wage' => ['nullable', 'numeric', 'gte:min_wage'],
            'reduces_taxable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $settings = PayrollSetting::current();

        return [
            'settings' => [
                'working_days_per_month' => $settings->working_days_per_month,
                'hours_per_day' => $settings->hours_per_day,
                'overtime_normal_rate' => $settings->overtime_normal_rate,
                'overtime_rest_day_rate' => $settings->overtime_rest_day_rate,
                'overtime_holiday_rate' => $settings->overtime_holiday_rate,
                'deduct_absence' => $settings->deduct_absence,
                'deduct_unpaid_leave' => $settings->deduct_unpaid_leave,
                'late_deduction_mode' => $settings->late_deduction_mode,
                'late_grace_minutes' => $settings->late_grace_minutes,
                'late_amount_usd' => $settings->late_amount_usd,
                'late_amount_khr' => $settings->late_amount_khr,
                'deduct_early_leave' => $settings->deduct_early_leave,
                'tax_spouse_allowance' => $settings->tax_spouse_allowance,
                'tax_child_allowance' => $settings->tax_child_allowance,
                'tax_non_resident_rate' => $settings->tax_non_resident_rate,
            ],
            'tax_brackets' => TaxBracket::query()->orderBy('min_amount')->get(['id', 'min_amount', 'max_amount', 'rate']),
            'social_security_schemes' => SocialSecurityScheme::query()->orderBy('id')->get(['id', 'code', 'name', 'employee_rate', 'employer_rate', 'min_wage', 'max_wage', 'reduces_taxable', 'is_active']),
            'khr_per_usd' => $this->rules->khrPerUsd(now()->toDateString()),
        ];
    }
}

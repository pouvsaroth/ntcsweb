import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * HRM > Payroll's set-up — pay components (allowances, bonuses,
 * deductions), salary structures, each staff member's basic salary over
 * time, and the items given to one staff member. See
 * PayrollComponentController, SalaryStructureController,
 * StaffSalaryController and StaffPayComponentController on the backend.
 * Every amount is a monthly one.
 */
export type PayCurrency = 'USD' | 'KHR'
export type PayComponentKind = 'allowance' | 'bonus' | 'deduction'
export type PayCalculation = 'fixed' | 'percent_of_basic'
export type PaymentMethod = 'bank' | 'cash'
export type PayRecurrence = 'recurring' | 'once'

export interface PayComponent {
  id: number
  kind: PayComponentKind
  code: string
  name: string
  calculation: PayCalculation
  affects_tax: boolean
  affects_social_security: boolean
  description: string | null
  is_active: boolean
  staff_assignments_count?: number
  structure_items_count?: number
}

export type PayComponentInput = Omit<PayComponent, 'id' | 'staff_assignments_count' | 'structure_items_count'>

export interface PayComponentRef {
  id: number
  kind: PayComponentKind
  code: string
  name: string
  calculation: PayCalculation
}

export interface SalaryStructure {
  id: number
  code: string
  name: string
  currency: PayCurrency
  description: string | null
  is_active: boolean
  items: { id: number; payroll_component_id: number; amount: string; component: PayComponentRef | null }[]
  salaries_count?: number
}

export interface SalaryStructureInput {
  code: string
  name: string
  currency: PayCurrency
  description: string | null
  is_active: boolean
  items: { payroll_component_id: number; amount: number }[]
}

export interface StaffSalary {
  id: number
  staff_id: number
  basic_salary: string
  currency: PayCurrency
  salary_structure_id: number | null
  structure?: { id: number; code: string; name: string } | null
  effective_from: string
  payment_method: PaymentMethod
  bank_name: string | null
  bank_account_name: string | null
  bank_account_number: string | null
  note: string | null
  created_at: string | null
}

export interface StaffSalaryInput {
  staff_id?: number
  basic_salary: number
  currency: PayCurrency
  salary_structure_id: number | null
  effective_from: string
  payment_method: PaymentMethod
  bank_name: string | null
  bank_account_name: string | null
  bank_account_number: string | null
  note: string | null
}

export interface PayrollStaff {
  id: number
  name: string
  employee_code: string | null
  position: string | null
  department: string | null
  hire_date: string | null
}

export interface StaffSalaryRow {
  staff: PayrollStaff
  /** In effect today — null when none is set yet. */
  salary: StaffSalary | null
  /** A change already entered for a later date. */
  next_salary: StaffSalary | null
}

export interface StaffPayComponent {
  id: number
  staff_id: number
  staff?: { id: number; name: string; employee_code: string | null } | null
  payroll_component_id: number
  component?: PayComponentRef | null
  amount: string
  /** The staff member's current salary currency — null when no salary is set yet. */
  currency: PayCurrency | null
  recurrence: PayRecurrence
  starts_on: string
  ends_on: string | null
  note: string | null
}

export interface StaffPayComponentInput {
  staff_id?: number
  payroll_component_id: number
  amount: number
  recurrence: PayRecurrence
  starts_on: string
  ends_on: string | null
  note: string | null
}

async function page<T>(url: string, query: PaginatedQuery, extra: Record<string, string | number> = {}): Promise<PaginatedResult<T>> {
  const result = await apiGetWithMeta<T[]>(url, {
    params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter, ...extra },
  })
  return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
}

export const payComponentsService = {
  list: (query: PaginatedQuery) => page<PayComponent>('/payroll-components', query),
  async listAll(kind?: PayComponentKind): Promise<PayComponent[]> {
    return (await apiGetWithMeta<PayComponent[]>('/payroll-components', { params: { per_page: 200, filter: kind ? { kind } : {} } })).data
  },
  create: (input: PayComponentInput) => apiPost<PayComponent>('/payroll-components', input),
  update: (id: number, input: Partial<PayComponentInput>) => apiPut<PayComponent>(`/payroll-components/${id}`, input),
  remove: (id: number) => apiDelete(`/payroll-components/${id}`),
}

export const salaryStructuresService = {
  list: (query: PaginatedQuery) => page<SalaryStructure>('/salary-structures', query),
  async listAll(): Promise<SalaryStructure[]> {
    return (await apiGetWithMeta<SalaryStructure[]>('/salary-structures', { params: { per_page: 200 } })).data
  },
  create: (input: SalaryStructureInput) => apiPost<SalaryStructure>('/salary-structures', input),
  update: (id: number, input: Partial<SalaryStructureInput>) => apiPut<SalaryStructure>(`/salary-structures/${id}`, input),
  remove: (id: number) => apiDelete(`/salary-structures/${id}`),
}

export const staffSalariesService = {
  list: (query: PaginatedQuery, missingOnly = false) => page<StaffSalaryRow>('/staff-salaries', query, missingOnly ? { missing_only: 1 } : {}),
  history: (staffId: number) =>
    apiGetWithMeta<{ staff: PayrollStaff; salaries: StaffSalary[] }>(`/staff-salaries/history/${staffId}`).then((r) => r.data),
  create: (input: StaffSalaryInput) => apiPost<StaffSalary>('/staff-salaries', input),
  update: (id: number, input: Partial<StaffSalaryInput>) => apiPut<StaffSalary>(`/staff-salaries/${id}`, input),
  remove: (id: number) => apiDelete(`/staff-salaries/${id}`),
}

export const staffPayComponentsService = {
  list: (query: PaginatedQuery, kind: PayComponentKind, currentOnly: boolean) =>
    page<StaffPayComponent>('/staff-pay-components', query, { kind, ...(currentOnly ? { current: 1 } : {}) }),
  create: (input: StaffPayComponentInput) => apiPost<StaffPayComponent>('/staff-pay-components', input),
  update: (id: number, input: Partial<StaffPayComponentInput>) => apiPut<StaffPayComponent>(`/staff-pay-components/${id}`, input),
  remove: (id: number) => apiDelete(`/staff-pay-components/${id}`),
}

/** "$30.00", "40,000 ៛" or "10%" — a component amount as it's meant. */
export function payAmountLabel(amount: string | number, calculation: PayCalculation | undefined, currency: PayCurrency | null): string {
  const value = Number(amount)
  if (calculation === 'percent_of_basic') return `${Number.isInteger(value) ? value : value.toFixed(2)}%`
  if (currency === 'KHR') return `${Math.round(value).toLocaleString()} ៛`
  if (currency === 'USD') return `$${value.toFixed(2)}`
  return value.toLocaleString()
}

// --- Stage 2: rules ------------------------------------------------------------------

export type LateDeductionMode = 'none' | 'per_minute' | 'per_occurrence'
export type PayPeriod = 'month' | 'first_half' | 'second_half'

export interface PayrollSettings {
  working_days_per_month: number
  hours_per_day: number
  overtime_normal_rate: number
  overtime_rest_day_rate: number
  overtime_holiday_rate: number
  deduct_absence: boolean
  deduct_unpaid_leave: boolean
  late_deduction_mode: LateDeductionMode
  late_grace_minutes: number
  late_amount_usd: number
  late_amount_khr: number
  deduct_early_leave: boolean
  /** Riel a month. */
  tax_spouse_allowance: number
  tax_child_allowance: number
  tax_non_resident_rate: number
}

export interface TaxBracket {
  id?: number
  /** Riel a month. */
  min_amount: number | string
  max_amount: number | string | null
  rate: number | string
}

export interface SocialSecurityScheme {
  id: number
  code: string
  name: string
  employee_rate: number
  employer_rate: number
  /** Riel a month. */
  min_wage: number
  max_wage: number | null
  reduces_taxable: boolean
  is_active: boolean
}

export type SocialSecuritySchemeInput = Omit<SocialSecurityScheme, 'id'>

export interface PayrollRules {
  settings: PayrollSettings
  tax_brackets: TaxBracket[]
  social_security_schemes: SocialSecurityScheme[]
  /** The school's KHR-per-USD rate today — null when none is entered. */
  khr_per_usd: number | null
}

export interface PayrollPreview {
  khr_per_usd: number | null
  needs_rate: boolean
  wage_khr: number
  social_security: {
    lines: { code: string; name: string; base: number; employee: number; employer: number; reduces_taxable: boolean }[]
    employee: number
    employer: number
    reduces_taxable: number
  }
  tax: { taxable: number; allowances: number; base: number; tax: number }
  in_currency: { currency: PayCurrency; social_security_employee: number; social_security_employer: number; tax: number; net: number }
}

export interface StaffPayrollProfile {
  saved: boolean
  tax_resident: boolean
  spouse_dependent: boolean
  child_dependents: number
  social_security_enrolled: boolean
  social_security_number: string | null
}

export type StaffPayrollProfileInput = Omit<StaffPayrollProfile, 'saved'>

export interface StaffProfileRow {
  staff: { id: number; name: string; employee_code: string | null }
  profile: StaffPayrollProfile
}

export interface OvertimeRow {
  staff: { id: number; name: string; employee_code: string | null }
  minutes: { normal: number; rest_day: number; holiday: number }
  total_minutes: number
  currency: PayCurrency | null
  hourly_rate: number | null
  amount: number | null
}

export interface DeductionRow {
  staff: { id: number; name: string; employee_code: string | null }
  absent_days: number
  unpaid_leave_days: number
  late_times: number
  late_minutes: number
  early_leave_times: number
  early_leave_minutes: number
  currency: PayCurrency | null
  lines: { absence: number; unpaid_leave: number; late: number; early_leave: number } | null
  amount: number | null
}

export interface PeriodResult<T> {
  from: string
  to: string
  rows: T[]
}

export type LoanType = 'loan' | 'advance'
export type LoanStatus = 'active' | 'settled' | 'cancelled'

export interface StaffLoan {
  id: number
  staff: { id: number; name: string; employee_code: string | null } | null
  type: LoanType
  amount: string
  currency: PayCurrency
  issued_on: string
  installment_amount: string
  first_deduction_on: string
  status: LoanStatus
  reason: string | null
  repaid: number
  balance: number
  repayments: { id: number; amount: string; paid_on: string; method: 'payroll' | 'cash'; note: string | null }[] | null
}

export interface StaffLoanInput {
  staff_id?: number
  type?: LoanType
  amount: number
  issued_on?: string
  installment_amount: number
  first_deduction_on: string
  reason: string | null
}

export const payrollRulesService = {
  get: () => apiGetWithMeta<PayrollRules>('/payroll-rules').then((r) => r.data),
  updateSettings: (input: Partial<PayrollSettings>) => apiPut<PayrollRules>('/payroll-rules/settings', input),
  replaceBrackets: (brackets: TaxBracket[]) => apiPut<PayrollRules>('/payroll-rules/tax-brackets', { brackets }),
  preview: (params: { amount: number; currency: PayCurrency; tax_resident: boolean; spouse_dependent: boolean; child_dependents: number; social_security_enrolled: boolean }) =>
    apiGetWithMeta<PayrollPreview>('/payroll-rules/preview', {
      params: {
        ...params,
        tax_resident: params.tax_resident ? 1 : 0,
        spouse_dependent: params.spouse_dependent ? 1 : 0,
        social_security_enrolled: params.social_security_enrolled ? 1 : 0,
      },
    }).then((r) => r.data),
  createScheme: (input: SocialSecuritySchemeInput) => apiPost<PayrollRules>('/social-security-schemes', input),
  updateScheme: (id: number, input: Partial<SocialSecuritySchemeInput>) => apiPut<PayrollRules>(`/social-security-schemes/${id}`, input),
  removeScheme: (id: number) => apiDelete<PayrollRules>(`/social-security-schemes/${id}`),
}

export const staffPayrollProfilesService = {
  list: (query: PaginatedQuery) => page<StaffProfileRow>('/staff-payroll-profiles', query),
  update: (staffId: number, input: Partial<StaffPayrollProfileInput>) => apiPut<StaffProfileRow>(`/staff-payroll-profiles/${staffId}`, input),
}

export const payrollPeriodService = {
  overtime: (month: string, period: PayPeriod) =>
    apiGetWithMeta<PeriodResult<OvertimeRow>>('/payroll-overtime', { params: { month, period } }).then((r) => r.data),
  deductions: (month: string, period: PayPeriod) =>
    apiGetWithMeta<PeriodResult<DeductionRow>>('/payroll-attendance-deductions', { params: { month, period } }).then((r) => r.data),
}

export const staffLoansService = {
  list: (query: PaginatedQuery) => page<StaffLoan>('/staff-loans', query),
  get: (id: number) => apiGetWithMeta<StaffLoan>(`/staff-loans/${id}`).then((r) => r.data),
  create: (input: StaffLoanInput) => apiPost<StaffLoan>('/staff-loans', input),
  update: (id: number, input: Partial<StaffLoanInput>) => apiPut<StaffLoan>(`/staff-loans/${id}`, input),
  cancel: (id: number) => apiPost<StaffLoan>(`/staff-loans/${id}/cancel`, {}),
  remove: (id: number) => apiDelete(`/staff-loans/${id}`),
  repay: (id: number, input: { amount: number; paid_on: string; note: string | null }) => apiPost<StaffLoan>(`/staff-loans/${id}/repayments`, input),
  removeRepayment: (repaymentId: number) => apiDelete<StaffLoan>(`/staff-loan-repayments/${repaymentId}`),
}

/** "1,500,000 ៛" — tax and NSSF amounts, which are always in riel. */
export function riel(amount: number | string | null): string {
  return amount === null || amount === '' ? '—' : `${Math.round(Number(amount)).toLocaleString()} ៛`
}

/** "2h 30m" from minutes. */
export function hoursLabel(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m === 0 ? `${h}h` : h === 0 ? `${m}m` : `${h}h ${m}m`
}

/** "2026-10" for the current month. */
export function currentMonth(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

// --- Stage 3: payroll runs and payslips ------------------------------------------------

export type PayrollRunStatus = 'draft' | 'pending' | 'approved' | 'rejected' | 'paid' | 'cancelled'

export interface PayrollTotals {
  currency: PayCurrency
  staff_count: number
  basic_pay: number
  allowances: number
  bonuses: number
  overtime_pay: number
  gross_pay: number
  attendance_deduction: number
  other_deductions: number
  social_security_employee: number
  social_security_employer: number
  tax: number
  loan_deduction: number
  adjustment: number
  net_pay: number
}

export interface PayslipLine {
  /** basic | allowance | bonus | overtime | adjustment (earnings); attendance | deduction | social_security | tax | loan | adjustment (deductions) */
  type: string
  code: string
  name: string
  amount: number
  loan_id?: number
}

export interface Payslip {
  id: number
  payroll_run_id: number
  run: { id: number; reference: string; period: PayPeriod; period_start: string; period_end: string; pay_date: string; status: PayrollRunStatus } | null
  staff: { id: number; name: string; employee_code: string | null; position: string | null; department: string | null } | null
  currency: PayCurrency
  monthly_basic: number
  basic_pay: number
  allowances: number
  bonuses: number
  overtime_pay: number
  gross_pay: number
  attendance_deduction: number
  other_deductions: number
  social_security_employee: number
  social_security_employer: number
  tax: number
  loan_deduction: number
  adjustment: number
  adjustment_note: string | null
  net_pay: number
  lines: { earnings: PayslipLine[]; deductions: PayslipLine[]; employer: PayslipLine[] }
  /** Only on a single payslip (the working behind it). */
  details: {
    overtime?: { minutes: { normal: number; rest_day: number; holiday: number } | null; hourly_rate: number | null }
    attendance?: { absent_days?: number; unpaid_leave_days?: number; late_times?: number; late_minutes?: number; early_leave_times?: number; early_leave_minutes?: number }
    taxed?: boolean
    khr_per_usd?: number | null
    tax?: { taxable: number; allowances: number; base: number; tax: number }
    structure?: string | null
  } | null
  payment_method: PaymentMethod | null
  bank_name: string | null
  bank_account_name: string | null
  bank_account_number: string | null
}

export interface PayrollRun {
  id: number
  reference: string
  month: string
  period: PayPeriod
  period_start: string
  period_end: string
  pay_date: string
  status: PayrollRunStatus
  khr_per_usd: number | null
  note: string | null
  calculated_at: string | null
  requested_by: string | null
  submitted_at: string | null
  decided_by: string | null
  decided_at: string | null
  decision_reason: string | null
  paid_at: string | null
  totals: PayrollTotals[]
  approval_flow: { step: number; total: number; group: string | null; can_act: boolean } | null
  can_decide: boolean
  payslips?: Payslip[]
  expense?: { id: number; expense_number: string; amount: string } | null
}

export interface PayAccounts {
  expense: { id: number; code: string; name: string }[]
  cash: { id: number; code: string; name: string }[]
}

export const payrollRunsService = {
  list: (query: PaginatedQuery, extra: { statuses?: string; year?: number } = {}) =>
    page<PayrollRun>('/payroll-runs', query, Object.fromEntries(Object.entries(extra).filter(([, v]) => v !== undefined && v !== '')) as Record<string, string | number>),
  get: (id: number) => apiGetWithMeta<PayrollRun>(`/payroll-runs/${id}`).then((r) => r.data),
  create: (input: { month: string; period: PayPeriod; pay_date: string; note: string | null }) => apiPost<PayrollRun>('/payroll-runs', input),
  update: (id: number, input: { pay_date?: string; note?: string | null }) => apiPut<PayrollRun>(`/payroll-runs/${id}`, input),
  recalculate: (id: number) => apiPost<PayrollRun>(`/payroll-runs/${id}/recalculate`, {}),
  submit: (id: number) => apiPost<PayrollRun>(`/payroll-runs/${id}/submit`, {}),
  approve: (id: number) => apiPost<PayrollRun>(`/payroll-runs/${id}/approve`, {}),
  reject: (id: number, reason: string) => apiPost<PayrollRun>(`/payroll-runs/${id}/reject`, { reason }),
  pay: (id: number, input: { expense_account_id: number; cash_account_id: number; paid_on: string }) => apiPost<PayrollRun>(`/payroll-runs/${id}/pay`, input),
  cancel: (id: number) => apiPost<PayrollRun>(`/payroll-runs/${id}/cancel`, {}),
  payAccounts: () => apiGetWithMeta<PayAccounts>('/payroll-runs/pay-accounts').then((r) => r.data),
  adjust: (payslipId: number, input: { adjustment: number; adjustment_note: string | null }) => apiPut<PayrollRun>(`/payslips/${payslipId}/adjustment`, input),
  payslip: (id: number) => apiGetWithMeta<Payslip>(`/payslips/${id}`).then((r) => r.data),
  payslips: (query: PaginatedQuery, extra: { year?: number } = {}) =>
    page<Payslip>('/payslips', query, extra.year ? { year: extra.year } : {}),
}

/** "Oct 2026" / "1–15 Oct 2026" — a run's pay period, short. */
export function runPeriodLabel(run: { period: PayPeriod; period_start: string; period_end: string }, locale: string): string {
  const start = new Date(`${run.period_start}T00:00:00`)
  const monthYear = start.toLocaleDateString(locale, { month: 'short', year: 'numeric' })
  if (run.period === 'month') return monthYear
  const end = new Date(`${run.period_end}T00:00:00`)
  return `${start.getDate()}–${end.getDate()} ${monthYear}`
}

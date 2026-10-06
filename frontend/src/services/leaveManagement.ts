import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LeaveBalance } from '@/services/leaveRequests'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * HRM > Leave Management's set-up — leave types and the policies that say
 * how many days of each a staff member gets a year. See LeaveTypeController
 * and LeavePolicyController on the backend.
 */
export type LeaveGender = 'male' | 'female'

export interface LeaveType {
  id: number
  code: string
  name: string
  color: string | null
  is_paid: boolean
  allow_half_day: boolean
  requires_attachment: boolean
  gender: LeaveGender | null
  description: string | null
  is_active: boolean
  policies_count?: number
}

export interface LeaveTypeInput {
  code: string
  name: string
  color: string | null
  is_paid: boolean
  allow_half_day: boolean
  requires_attachment: boolean
  gender: LeaveGender | null
  description: string | null
  is_active: boolean
}

export interface LeavePolicy {
  id: number
  leave_type_id: number
  leave_type?: { id: number; code: string; name: string; color: string | null }
  job_grade_id: number | null
  job_grade?: { id: number; code: string; name: string } | null
  name: string
  days_per_year: number
  min_service_months: number
  prorate_first_year: boolean
  service_bonus_every_years: number | null
  service_bonus_days: number
  max_days_per_year: number | null
  max_carry_forward_days: number
  carry_forward_expiry_months: number | null
  max_consecutive_days: number | null
  min_notice_days: number
  description: string | null
  is_active: boolean
}

export type LeavePolicyInput = Omit<LeavePolicy, 'id' | 'leave_type' | 'job_grade'>

async function page<T>(url: string, query: PaginatedQuery, extra: Record<string, string | number> = {}): Promise<PaginatedResult<T>> {
  const result = await apiGetWithMeta<T[]>(url, {
    params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter, ...extra },
  })
  return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
}

export const leaveTypesService = {
  list: (query: PaginatedQuery) => page<LeaveType>('/leave-types', query),
  async listAll(): Promise<LeaveType[]> {
    return (await apiGetWithMeta<LeaveType[]>('/leave-types', { params: { per_page: 200 } })).data
  },
  create: (input: LeaveTypeInput) => apiPost<LeaveType>('/leave-types', input),
  update: (id: number, input: Partial<LeaveTypeInput>) => apiPut<LeaveType>(`/leave-types/${id}`, input),
  remove: (id: number) => apiDelete(`/leave-types/${id}`),
}

export const leavePoliciesService = {
  list: (query: PaginatedQuery) => page<LeavePolicy>('/leave-policies', query),
  create: (input: LeavePolicyInput) => apiPost<LeavePolicy>('/leave-policies', input),
  update: (id: number, input: Partial<LeavePolicyInput>) => apiPut<LeavePolicy>(`/leave-policies/${id}`, input),
  remove: (id: number) => apiDelete(`/leave-policies/${id}`),
}

// --- Balances and carry forward (LeaveBalanceController) --------------------------------

export interface LeaveTypeRef {
  id: number
  code: string
  name: string
  color: string | null
}

export interface BalanceStaff {
  id: number
  name: string
  employee_code: string
  hire_date: string | null
}

export type StaffLeaveBalance = LeaveBalance & { leave_type: LeaveTypeRef }

export interface StaffBalanceRow {
  staff: BalanceStaff
  balances: StaffLeaveBalance[]
}

export interface LeaveBalanceEntry {
  id: number
  leave_type: LeaveTypeRef | null
  year: number
  kind: 'adjustment' | 'carry_forward'
  days: number
  expires_on: string | null
  note: string | null
  created_by: string | null
  created_at: string
  /** Carry forward list only. */
  staff?: BalanceStaff | null
}

export interface StaffBalanceDetail {
  staff: BalanceStaff
  year: number
  balances: StaffLeaveBalance[]
  entries: LeaveBalanceEntry[]
}

export interface CarryForwardResult {
  entries: number
  days: number
  /** Requests of the year still waiting — counted as taken. */
  pending_requests: number
}

export const leaveBalancesService = {
  list: (query: PaginatedQuery, year: number, leaveTypeId: number | null) =>
    page<StaffBalanceRow>('/leave-balances', { ...query, filter: undefined }, { year, ...(leaveTypeId ? { leave_type_id: leaveTypeId } : {}) }),
  get: (staffId: number, year: number) => apiGetWithMeta<StaffBalanceDetail>(`/leave-balances/${staffId}`, { params: { year } }).then((r) => r.data),
  adjust: (input: { staff_id: number; leave_type_id: number; year: number; days: number; note: string }) => apiPost<LeaveBalanceEntry>('/leave-balances/adjustments', input),
  removeEntry: (id: number) => apiDelete(`/leave-balance-entries/${id}`),
  carried: (query: PaginatedQuery, year: number) => page<LeaveBalanceEntry>('/leave-balances/carry-forward', query, { year }),
  carryForward: (fromYear: number) => apiPost<CarryForwardResult>('/leave-balances/carry-forward', { from_year: fromYear }),
}

// --- Calendar, reports, approval workflow (LeaveReportController) ------------------------

export interface CalendarLeave {
  id: number
  staff: { id: number; name: string; employee_code: string } | null
  leave_type: LeaveTypeRef | null
  from_date: string
  to_date: string
  day_part: 'full' | 'morning' | 'afternoon' | null
  days: number | null
  status: 'pending' | 'approved' | 'rejected'
}

export interface LeaveCalendar {
  from: string
  to: string
  holidays: { id: number; name: string; start_date: string; end_date: string }[]
  leaves: CalendarLeave[]
}

export interface LeaveReport {
  year: number
  totals: { days: number; requests: number; staff: number; pending_requests: number; pending_days: number; rejected_requests: number }
  by_type: { leave_type: LeaveTypeRef; days: number; requests: number; staff: number }[]
  by_month: { month: number; days: number; by_type: Record<string, number> }[]
  by_staff: { staff: { id: number; name: string; employee_code: string }; days: number; requests: number; by_type: Record<string, number> }[]
}

export interface LeaveWorkflow {
  steps: { step_order: number; group: { id: number; name: string | null; members: string[] } }[]
  year: number
  counts: { pending: number; approved: number; rejected: number }
  oldest_pending_at: string | null
}

export const leaveReportsService = {
  calendar: (month: string, opts: { leaveTypeId?: number | null; includePending?: boolean } = {}) =>
    apiGetWithMeta<LeaveCalendar>('/leave-calendar', {
      params: { month, ...(opts.leaveTypeId ? { leave_type_id: opts.leaveTypeId } : {}), ...(opts.includePending ? { include_pending: 1 } : {}) },
    }).then((r) => r.data),
  report: (year: number, leaveTypeId: number | null) =>
    apiGetWithMeta<LeaveReport>('/leave-reports', { params: { year, ...(leaveTypeId ? { leave_type_id: leaveTypeId } : {}) } }).then((r) => r.data),
  workflow: () => apiGetWithMeta<LeaveWorkflow>('/leave-workflow').then((r) => r.data),
}

/** 18 → "18", 1.5 → "1.5" — leave is counted in half days. */
export function formatDays(days: number): string {
  return Number.isInteger(days) ? String(days) : days.toFixed(1)
}

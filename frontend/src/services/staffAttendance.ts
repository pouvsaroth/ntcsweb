import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * Staff attendance (HRM > Attendance & Time): the staff member's own
 * check-in / check-out, HR's records and imports, and the month sheet —
 * see MyStaffAttendanceController and StaffAttendanceController.
 *
 * Times come back with the school's offset ("…+07:00"), so `new Date()`
 * shows them right in the browser.
 */
export type DayStatus = 'present' | 'late' | 'early_leave' | 'incomplete' | 'absent' | 'holiday' | 'leave' | 'off' | 'upcoming' | 'none'

export const DAY_STATUSES: DayStatus[] = ['present', 'late', 'early_leave', 'incomplete', 'absent', 'holiday', 'leave', 'off']

/** One-letter cell + colour for the month sheet. */
export const DAY_STYLE: Record<DayStatus, { short: string; cls: string }> = {
  present: { short: '✓', cls: 'bg-success-50 text-success-600' },
  late: { short: 'L', cls: 'bg-amber-100 text-amber-800' },
  early_leave: { short: 'E', cls: 'bg-orange-100 text-orange-800' },
  incomplete: { short: '…', cls: 'bg-primary-50 text-primary-700' },
  absent: { short: 'A', cls: 'bg-red-100 text-red-700' },
  holiday: { short: 'H', cls: 'bg-purple-100 text-purple-700' },
  leave: { short: 'LV', cls: 'bg-sky-100 text-sky-700' },
  off: { short: '–', cls: 'text-neutral-300' },
  upcoming: { short: '', cls: '' },
  none: { short: '', cls: 'bg-neutral-50' },
}

export const STATUS_VARIANT: Record<DayStatus, 'success' | 'warning' | 'danger' | 'primary' | 'neutral'> = {
  present: 'success',
  late: 'warning',
  early_leave: 'warning',
  incomplete: 'primary',
  absent: 'danger',
  holiday: 'primary',
  leave: 'primary',
  off: 'neutral',
  upcoming: 'neutral',
  none: 'neutral',
}

export interface StaffAttendanceRecord {
  id: number
  staff_id: number
  staff?: { id: number; name: string; employee_code: string } | null
  date: string
  shift?: { id: number; name: string; start_time: string; end_time: string; color: string | null } | null
  scheduled_start: string | null
  scheduled_end: string | null
  check_in_at: string | null
  check_out_at: string | null
  check_in_source: 'app' | 'manual' | 'import' | 'correction' | null
  check_out_source: 'app' | 'manual' | 'import' | 'correction' | null
  check_in_location: { lat: number; lng: number } | null
  check_out_location: { lat: number; lng: number } | null
  status: DayStatus
  late_minutes: number
  early_leave_minutes: number
  worked_minutes: number
  overtime_minutes: number
  note: string | null
}

export interface SheetDay {
  date: string
  status: DayStatus
  id: number | null
  check_in_at?: string | null
  check_out_at?: string | null
  late_minutes?: number
  early_leave_minutes?: number
  worked_minutes?: number
  overtime_minutes?: number
  shift: { id: number; name: string; color: string | null } | null
}

export type SheetTotals = Record<'present' | 'late' | 'early_leave' | 'incomplete' | 'absent' | 'holiday' | 'leave' | 'off' | 'late_minutes' | 'early_leave_minutes' | 'worked_minutes' | 'overtime_minutes', number>

export interface SheetRow {
  id: number
  name: string
  employee_code: string
  days: SheetDay[]
  totals: SheetTotals
  /** The month is signed off (Attendance approval) — no more changes. */
  locked: boolean
}

export interface MyToday {
  now: string
  shift: { name: string; start_time: string; end_time: string } | null
  record: StaffAttendanceRecord | null
  can_check_in: boolean
  can_check_out: boolean
}

export interface ImportResult {
  days: number
  rows: number
  errors: string[]
}

export const staffAttendanceService = {
  async list(query: PaginatedQuery, extra: Record<string, string> = {}): Promise<PaginatedResult<StaffAttendanceRecord>> {
    const result = await apiGetWithMeta<StaffAttendanceRecord[]>('/staff-attendance', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter, ...extra },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  sheet: (params: { month: string; department_id?: number; branch_id?: number; search?: string }) =>
    apiGet<{ month: string; staff: SheetRow[] }>('/staff-attendance/sheet', { params }),

  /** HR sets a day's times (local "HH:MM"; empty clears that side). */
  record: (input: { staff_id: number; date: string; check_in: string | null; check_out: string | null; note: string | null }) =>
    apiPost<StaffAttendanceRecord>('/staff-attendance', input),
  update: (id: number, input: { check_in: string | null; check_out: string | null; note: string | null }) =>
    apiPut<StaffAttendanceRecord>(`/staff-attendance/${id}`, input),
  remove: (id: number) => apiDelete(`/staff-attendance/${id}`),

  import(file: File): Promise<ImportResult> {
    const form = new FormData()
    form.append('file', file)
    return apiPost<ImportResult>('/staff-attendance/import', form)
  },
}

export const myStaffAttendanceService = {
  today: () => apiGet<MyToday>('/my-staff-attendance/today'),
  month: (month: string) => apiGet<{ month: string; days: SheetDay[]; totals: SheetTotals }>('/my-staff-attendance', { params: { month } }),
  checkIn: (location: { latitude: number; longitude: number } | null) => apiPost<StaffAttendanceRecord>('/my-staff-attendance/check-in', location ?? {}),
  checkOut: (location: { latitude: number; longitude: number } | null) => apiPost<StaffAttendanceRecord>('/my-staff-attendance/check-out', location ?? {}),
}

/** "08:05" in the browser's time, or "—". */
export function clock(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

/** The phone's position if it gives one within a few seconds; never blocks checking in. */
export function currentLocation(): Promise<{ latitude: number; longitude: number } | null> {
  if (!('geolocation' in navigator)) return Promise.resolve(null)
  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (position) => resolve({ latitude: position.coords.latitude, longitude: position.coords.longitude }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
    )
  })
}

/** This month as "YYYY-MM". */
export function thisMonth(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

// --- Reports and overtime ----------------------------------------------------------

export type ReportType = 'late' | 'early_leave' | 'absence' | 'overtime'

export interface ReportItem {
  staff: { id: number; name: string; employee_code: string }
  date: string
  minutes: number
  shift: { id: number; name: string; color: string | null } | null
  check_in_at: string | null
  check_out_at: string | null
  record_id?: number
}

export interface ReportSummary {
  staff_id: number
  name: string
  employee_code: string
  count: number
  minutes: number
}

export type OvertimeStatus = 'pending' | 'approved' | 'rejected'

export interface OvertimeRequest {
  id: number
  /** "OT-000003". */
  reference: string
  staff_id: number
  staff?: { id: number; name: string; employee_code: string } | null
  date: string
  minutes: number
  reason: string
  status: OvertimeStatus
  requested_by?: string | null
  /** Approvals queue only — see ApprovalFlowProgress. */
  approval_flow?: import('@/services/approvalFlows').ApprovalFlowProgress | null
  decision_reason: string | null
  decided_by?: string | null
  decided_at: string | null
  created_at: string
}

export const attendanceReportService = {
  get: (params: { type: ReportType; from: string; to: string; department_id?: number; search?: string }) =>
    apiGet<{ items: ReportItem[]; summary: ReportSummary[]; total: number }>('/staff-attendance/report', { params }),
}

export const overtimeRequestsService = {
  async list(query: PaginatedQuery, opts: { approvalQueue?: boolean; from?: string; to?: string } = {}): Promise<PaginatedResult<OvertimeRequest>> {
    const result = await apiGetWithMeta<OvertimeRequest[]>('/overtime-requests', {
      params: {
        page: query.page,
        per_page: query.per_page,
        search: query.search,
        sort: query.sort,
        filter: query.filter,
        ...(opts.approvalQueue ? { approval_queue: '1' } : {}),
        ...(opts.from ? { from: opts.from } : {}),
        ...(opts.to ? { to: opts.to } : {}),
      },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  /** HR files one for a staff member. */
  create: (input: { staff_id: number; date: string; minutes: number; reason: string }) => apiPost<OvertimeRequest>('/overtime-requests', input),
  remove: (id: number) => apiDelete(`/overtime-requests/${id}`),
  approve: (id: number) => apiPost<OvertimeRequest>(`/overtime-requests/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<OvertimeRequest>(`/overtime-requests/${id}/reject`, { reason }),
}

export const myOvertimeService = {
  list: () => apiGet<OvertimeRequest[]>('/my-overtime-requests'),
  create: (input: { date: string; minutes: number; reason: string }) => apiPost<OvertimeRequest>('/my-overtime-requests', input),
}

/** The first and last day of this month, as "YYYY-MM-DD". */
export function thisMonthRange(): { from: string; to: string } {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  const last = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()
  return { from: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-01`, to: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(last)}` }
}

// --- Corrections and month sign-off ------------------------------------------------

export interface AttendanceCorrection {
  id: number
  /** "AC-000005". */
  reference: string
  staff_id: number
  staff?: { id: number; name: string; employee_code: string } | null
  date: string
  /** Requested "HH:MM" (school time); null = leave as recorded. */
  check_in: string | null
  check_out: string | null
  /** What was recorded that day when the request was read. */
  recorded: { check_in_at: string | null; check_out_at: string | null; status: DayStatus | null }
  reason: string
  status: OvertimeStatus
  requested_by?: string | null
  /** Approvals queue only — see ApprovalFlowProgress. */
  approval_flow?: import('@/services/approvalFlows').ApprovalFlowProgress | null
  decision_reason: string | null
  decided_by?: string | null
  decided_at: string | null
  created_at: string
}

export interface CorrectionInput {
  date: string
  check_in: string | null
  check_out: string | null
  reason: string
}

export interface SignOffRow {
  staff: { id: number; name: string; employee_code: string }
  totals: SheetTotals
  incomplete: number
  approval: { id: number; approved_by: string | null; approved_at: string | null; note: string | null } | null
}

export const attendanceCorrectionsService = {
  async list(query: PaginatedQuery, opts: { approvalQueue?: boolean } = {}): Promise<PaginatedResult<AttendanceCorrection>> {
    const result = await apiGetWithMeta<AttendanceCorrection[]>('/attendance-corrections', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter, ...(opts.approvalQueue ? { approval_queue: '1' } : {}) },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  /** HR files one for a staff member. */
  create: (input: CorrectionInput & { staff_id: number }) => apiPost<AttendanceCorrection>('/attendance-corrections', input),
  remove: (id: number) => apiDelete(`/attendance-corrections/${id}`),
  approve: (id: number) => apiPost<AttendanceCorrection>(`/attendance-corrections/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<AttendanceCorrection>(`/attendance-corrections/${id}/reject`, { reason }),
}

export const myCorrectionsService = {
  list: () => apiGet<AttendanceCorrection[]>('/my-attendance-corrections'),
  create: (input: CorrectionInput) => apiPost<AttendanceCorrection>('/my-attendance-corrections', input),
}

export const attendanceSignOffService = {
  list: (params: { month: string; department_id?: number; search?: string }) => apiGet<{ month: string; staff: SignOffRow[] }>('/attendance-approvals', { params }),
  signOff: (input: { month: string; staff_ids: number[]; note: string | null }) => apiPost<{ signed_off: number }>('/attendance-approvals', input),
  unlock: (id: number) => apiDelete(`/attendance-approvals/${id}`),
}

/** Last month as "YYYY-MM" — the usual one to sign off. */
export function lastMonth(): string {
  const d = new Date()
  d.setDate(1)
  d.setMonth(d.getMonth() - 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

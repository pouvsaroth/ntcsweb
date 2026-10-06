import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * HRM > Attendance & Time — staff attendance and what it's measured
 * against (shifts, work schedules, holidays). See ShiftController,
 * WorkScheduleController and HolidayController on the backend.
 */
export const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const
export type Weekday = (typeof WEEKDAYS)[number]

export interface Shift {
  id: number
  code: string
  name: string
  /** "HH:MM". */
  start_time: string
  end_time: string
  break_minutes: number
  late_grace_minutes: number
  early_leave_grace_minutes: number
  /** Start to end, less the break. */
  work_minutes: number
  /** Ends the next day. */
  overnight: boolean
  color: string | null
  is_active: boolean
}

export type ShiftInput = Omit<Shift, 'id' | 'work_minutes' | 'overnight'>

export type WorkSchedule = {
  id: number
  name: string
  description: string | null
  is_default: boolean
  staff_count?: number
  staff?: { id: number; name: string; employee_code: string }[]
} & Record<`${Weekday}_shift_id`, number | null>

export type WorkScheduleInput = {
  name: string
  description: string | null
  is_default: boolean
  staff_ids?: number[]
} & Record<`${Weekday}_shift_id`, number | null>

export interface Holiday {
  id: number
  name: string
  start_date: string
  end_date: string
  days: number
  description: string | null
}

export interface HolidayInput {
  name: string
  start_date: string
  end_date: string | null
  description: string | null
}

async function page<T>(url: string, query: PaginatedQuery, extra: Record<string, string | number> = {}): Promise<PaginatedResult<T>> {
  const result = await apiGetWithMeta<T[]>(url, {
    params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter, ...extra },
  })
  return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
}

export const shiftsService = {
  list: (query: PaginatedQuery) => page<Shift>('/shifts', query),
  async listAll(): Promise<Shift[]> {
    return (await apiGetWithMeta<Shift[]>('/shifts', { params: { per_page: 200 } })).data
  },
  create: (input: ShiftInput) => apiPost<Shift>('/shifts', input),
  update: (id: number, input: Partial<ShiftInput>) => apiPut<Shift>(`/shifts/${id}`, input),
  remove: (id: number) => apiDelete(`/shifts/${id}`),
}

export const workSchedulesService = {
  list: (query: PaginatedQuery) => page<WorkSchedule>('/work-schedules', query),
  get: (id: number) => apiGetWithMeta<WorkSchedule>(`/work-schedules/${id}`).then((r) => r.data),
  create: (input: WorkScheduleInput) => apiPost<WorkSchedule>('/work-schedules', input),
  update: (id: number, input: Partial<WorkScheduleInput>) => apiPut<WorkSchedule>(`/work-schedules/${id}`, input),
  remove: (id: number) => apiDelete(`/work-schedules/${id}`),
}

export const holidaysService = {
  list: (query: PaginatedQuery, year: number | null) => page<Holiday>('/holidays', query, year ? { year } : {}),
  create: (input: HolidayInput) => apiPost<Holiday>('/holidays', input),
  update: (id: number, input: HolidayInput) => apiPut<Holiday>(`/holidays/${id}`, input),
  remove: (id: number) => apiDelete(`/holidays/${id}`),
}

/** 480 → "8h", 510 → "8h 30m". */
export function formatMinutes(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m === 0 ? `${h}h` : h === 0 ? `${m}m` : `${h}h ${m}m`
}

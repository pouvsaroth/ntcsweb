import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type AttendanceStatusValue = 'PRESENT' | 'ABSENT' | 'LATE' | 'EXCUSED'

export const attendanceStatuses: AttendanceStatusValue[] = ['PRESENT', 'ABSENT', 'LATE', 'EXCUSED']

export interface AttendanceStudent {
  id: number
  student_code: string
  name: string
}

/** One roster row = one enrolled student, paired with today's record if one already exists. */
export interface AttendanceRosterEntry {
  enrollment_id: number
  student: AttendanceStudent
  attendance_record_id: number | null
  status: AttendanceStatusValue | null
  remarks: string | null
}

export interface AttendanceRecord {
  id: number
  enrollment_id: number
  date: string
  status: AttendanceStatusValue
  remarks: string | null
  student?: AttendanceStudent
  class?: { id: number; name: string }
  recorded_by?: string | null
  recorded_at?: string | null
}

export interface AttendanceEntryInput {
  enrollment_id: number
  status: AttendanceStatusValue
  remarks?: string | null
}

export const attendanceService = {
  /** The "take attendance" screen's data source — every active enrollment in the class, paired with this date's record if one exists. */
  roster: (classId: number, date: string) =>
    apiGetWithMeta<AttendanceRosterEntry[]>(`/classes/${classId}/attendance`, { params: { date } }).then((r) => r.data),

  save: (classId: number, date: string, entries: AttendanceEntryInput[]) =>
    apiPost<AttendanceRecord[]>(`/classes/${classId}/attendance`, { date, entries }),

  /** History/review — filterable by class, student, status, and date range. */
  async list(query: PaginatedQuery): Promise<PaginatedResult<AttendanceRecord>> {
    const result = await apiGetWithMeta<AttendanceRecord[]>('/attendance', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Student self-service — own records only, scoped server-side. */
  async myList(query: PaginatedQuery): Promise<PaginatedResult<AttendanceRecord>> {
    const result = await apiGetWithMeta<AttendanceRecord[]>('/my-attendance', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
}

import { apiDownload, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { EnrollmentStatus } from '@/services/enrollments'
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
  /** The seat this student is checked in by — null when unassigned. Attendance is verified by this, not student_code. */
  table_no: string | null
  attendance_record_id: number | null
  status: AttendanceStatusValue | null
  late_minutes: number | null
  remarks: string | null
}

export interface AttendanceRecord {
  id: number
  enrollment_id: number
  date: string
  status: AttendanceStatusValue
  /** Only meaningful when status is LATE. */
  late_minutes: number | null
  remarks: string | null
  student?: AttendanceStudent
  /** The class this day was taken in; `hours` is what it counts in the Attendance Summary. */
  class?: { id: number; name: string; start_time: string | null; end_time: string | null; hours: number | null }
  recorded_by?: string | null
  recorded_at?: string | null
}

export interface AttendanceEntryInput {
  enrollment_id: number
  status: AttendanceStatusValue
  late_minutes?: number | null
  remarks?: string | null
}

/** One row of the Attendance Summary tab — one enrolled student's totals over a date range. */
export interface AttendanceSummaryRow {
  enrollment_id: number
  status: EnrollmentStatus
  student: AttendanceStudent
  school_class: { id: number; name: string } | null
  course_package: { id: number; name: string } | null
  present_days: number
  present_hours: number
  permission_days: number
  permission_hours: number
  absent_days: number
  absent_hours: number
  late_minutes: number
  /** Approved make-up class hours inside the same date range. */
  make_up_hours: number
}

/** Totals at the end of an Attendance Sheet row. */
export interface AttendanceSheetTotals {
  present: number
  late: number
  absent: number
  permission: number
  late_minutes: number
}

/**
 * The Attendance Sheet tab — one class over a date range: `dates` are the
 * days attendance was taken; each student's `marks` maps a date to
 * '✓' present, 'A' absent, 'P' permission, or the late minutes ('L' if late
 * with none entered), or '–' before the student's start study date (not
 * counted). A date missing from `marks` = not taken.
 */
export interface AttendanceSheet {
  class: { id: number; name: string }
  date_from: string
  date_to: string
  dates: string[]
  students: {
    enrollment_id: number
    status: EnrollmentStatus
    student: AttendanceStudent
    table: string | null
    /** Their start study date (yyyy-mm-dd); days before it are '–'. */
    start_study_on: string | null
    marks: Record<string, string>
    totals: AttendanceSheetTotals
  }[]
}

export interface AttendanceSheetQuery {
  date_from: string
  date_to: string
  /** Omitted = Studying only; 'all' = every status; or one enrollment status. */
  status?: string
}

/** One course the student is studying — see MyAttendanceController::hoursSummary(). */
export interface MyAttendanceHoursRow {
  enrollment_id: number
  course_package: { id: number; name: string } | null
  school_class: { id: number; name: string } | null
  absent_hours: number
  make_up_hours: number
  remaining_hours: number
}

/** What counts as "absent" everywhere — absent, permission (excused) and late. */
export const ABSENCE_STATUSES: AttendanceStatusValue[] = ['ABSENT', 'EXCUSED', 'LATE']

export const attendanceService = {
  /**
   * Every absent record for a single day — the Dashboard's "Absent
   * Today/Yesterday" lists. "Absent" means absent, permission and late,
   * same as the Absent(h) column on the Attendance Summary.
   */
  async listAbsent(date: string): Promise<AttendanceRecord[]> {
    const result = await apiGetWithMeta<AttendanceRecord[]>('/attendance', {
      params: { date_from: date, date_to: date, per_page: 200, filter: { status: ABSENCE_STATUSES.join(',') } },
    })
    return result.data
  },

  /** The "take attendance" screen's data source — every active enrollment in the class, paired with this date's record if one exists. */
  roster: (classId: number, date: string) =>
    apiGetWithMeta<AttendanceRosterEntry[]>(`/classes/${classId}/attendance`, { params: { date } }).then((r) => r.data),

  /**
   * The roster plus `missedDate`: the class's previous class day with no
   * attendance yet. While it is set, today's attendance can't be saved.
   * `dayOff`: the holiday or School Calendar closing day on that date (no class), if any.
   * `notHeld`: the teacher was away and nobody covered (Teacher > Substitute), so no class then.
   */
  rosterWithLock: (classId: number, date: string) =>
    apiGetWithMeta<AttendanceRosterEntry[]>(`/classes/${classId}/attendance`, { params: { date } }).then((r) => ({
      entries: r.data,
      missedDate: (r.meta?.missed_date as string | null | undefined) ?? null,
      dayOff: (r.meta?.day_off as string | null | undefined) ?? null,
      notHeld: r.meta?.not_held === true,
    })),

  save: (classId: number, date: string, entries: AttendanceEntryInput[]) =>
    apiPost<AttendanceRecord[]>(`/classes/${classId}/attendance`, { date, entries }),

  /** History/review — filterable by class, student, status, and date range. */
  async list(query: PaginatedQuery): Promise<PaginatedResult<AttendanceRecord>> {
    const result = await apiGetWithMeta<AttendanceRecord[]>('/attendance', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /**
   * The Attendance Summary tab's data source — one row per enrollment.
   * `class_id` omitted = every class. `status`: omitted = Studying only,
   * 'all' = every status, or one enrollment status.
   */
  summary: (params: { class_id?: number; date_from?: string; date_to?: string; student_id?: number | null; status?: string }) =>
    apiGetWithMeta<AttendanceSummaryRow[]>('/attendance-summary', { params }).then((r) => r.data),

  sheet: (classId: number, params: AttendanceSheetQuery) =>
    apiGetWithMeta<AttendanceSheet>(`/classes/${classId}/attendance-sheet`, { params }).then((r) => r.data),

  /** The sheet as an .xlsx or PDF file, saved under `filename`. */
  downloadSheet: (classId: number, format: 'excel' | 'pdf', params: AttendanceSheetQuery, filename: string) =>
    apiDownload(`/classes/${classId}/attendance-sheet/${format}?${new URLSearchParams({ ...params }).toString()}`, filename),

  /** Student self-service — own records only, scoped server-side. */
  async myList(query: PaginatedQuery): Promise<PaginatedResult<AttendanceRecord>> {
    const result = await apiGetWithMeta<AttendanceRecord[]>('/my-attendance', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Student home screen hour cards — absent / made up / left, per course they're studying. */
  myHoursSummary: () => apiGetWithMeta<MyAttendanceHoursRow[]>('/my-attendance/hours-summary').then((r) => r.data),
}

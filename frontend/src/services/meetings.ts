import { apiDelete, apiDownload, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { StaffOption } from '@/services/inquiries'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Front Office > Meetings — see backend MeetingController. */
export const MEETING_STATUSES = ['scheduled', 'completed', 'cancelled'] as const
export type MeetingStatus = (typeof MEETING_STATUSES)[number]
/** Who came to a meeting (a staff participant or a guest). */
export const MEETING_ATTENDANCE = ['present', 'late', 'absent', 'excused'] as const
export type MeetingAttendance = (typeof MEETING_ATTENDANCE)[number]

export type MeetingWhen = 'upcoming' | 'past' | ''

export interface Meeting {
  id: number
  title: string
  meeting_date: string
  start_time: string
  end_time: string | null
  location: string | null
  agenda: string | null
  minutes: string | null
  status: MeetingStatus
  organizer_staff_id: number | null
  organizer?: { id: number; name: string } | null
  /** Outside guests, one per line. */
  guests: string | null
  participants_count?: number
  participants?: (StaffOption & { attendance?: MeetingAttendance | null })[]
  /** Who came, by guest name. */
  guest_attendance?: Record<string, MeetingAttendance>
  created_at: string
}

export interface MeetingInput {
  title: string
  meeting_date: string
  start_time: string
  end_time: string | null
  location: string | null
  agenda: string | null
  organizer_staff_id: number | null
  guests: string | null
  participant_ids: number[]
  status?: MeetingStatus
  minutes?: string | null
}

/** Front Office > Meeting arrangement > Attendance report. */
export interface MeetingAttendanceReport {
  from: string
  to: string
  summary: {
    staff_id: number
    name: string
    employee_code: string | null
    meetings: number
    present: number
    late: number
    absent: number
    excused: number
    not_marked: number
    /** Present or late, out of the meetings that were marked; null when none were. */
    attended_percent: number | null
  }[]
  meetings: { id: number; date: string; time: string; title: string; people: { name: string; guest: boolean; attendance: MeetingAttendance | null }[] }[]
}

export interface MeetingSummary {
  today: number
  upcoming: number
  this_month: number
  completed: number
}

export const meetingsService = {
  async list(query: PaginatedQuery & { when?: MeetingWhen; mine?: boolean }): Promise<PaginatedResult<Meeting>> {
    const result = await apiGetWithMeta<Meeting[]>('/meetings', {
      params: {
        page: query.page,
        per_page: query.per_page,
        search: query.search,
        sort: query.sort,
        filter: query.filter,
        when: query.when || undefined,
        mine: query.mine ? 1 : undefined,
      },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  summary: () => apiGet<MeetingSummary>('/meetings/summary'),
  staffOptions: () => apiGet<StaffOption[]>('/meetings/staff-options'),
  get: (id: number) => apiGet<Meeting>(`/meetings/${id}`),
  create: (input: MeetingInput) => apiPost<Meeting>('/meetings', input),
  update: (id: number, input: Partial<MeetingInput>) => apiPut<Meeting>(`/meetings/${id}`, input),
  attendanceReport: (range: { from: string; to: string }) => apiGet<MeetingAttendanceReport>('/meetings/attendance-report', { params: range }),
  downloadAttendanceReport: (range: { from: string; to: string }) =>
    apiDownload(`/meetings/attendance-report/excel?${new URLSearchParams(range).toString()}`, `meeting-attendance-${range.from}-to-${range.to}.xlsx`),
  /** Mark who came; only the people sent change, null clears a mark. */
  saveAttendance: (
    id: number,
    input: {
      participants: { staff_id: number; attendance: MeetingAttendance | null }[]
      guests: { name: string; attendance: MeetingAttendance | null }[]
    },
  ) => apiPut<Meeting>(`/meetings/${id}/attendance`, input),
  remove: (id: number) => apiDelete(`/meetings/${id}`),
}

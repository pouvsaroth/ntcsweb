import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { StaffOption } from '@/services/inquiries'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Front Office > Appointments and Visitors — see backend AppointmentController / VisitorController. */
export const APPOINTMENT_STATUSES = ['scheduled', 'checked_in', 'completed', 'cancelled', 'no_show'] as const
export type AppointmentStatus = (typeof APPOINTMENT_STATUSES)[number]
export type AppointmentWhen = 'today' | 'upcoming' | 'past' | ''

export interface Appointment {
  id: number
  visitor_name: string
  phone: string | null
  email: string | null
  organization: string | null
  purpose: string
  appointment_date: string
  start_time: string
  end_time: string | null
  host_staff_id: number | null
  host?: { id: number; name: string } | null
  status: AppointmentStatus
  note: string | null
  visitor?: { id: number; check_in_at: string; check_out_at: string | null } | null
  created_at: string
}

export interface AppointmentInput {
  visitor_name: string
  phone: string | null
  email: string | null
  organization: string | null
  purpose: string
  appointment_date: string
  start_time: string
  end_time: string | null
  host_staff_id: number | null
  note: string | null
}

export interface AppointmentSummary {
  today: number
  waiting: number
  upcoming: number
  no_show: number
}

export interface Visitor {
  id: number
  appointment_id: number | null
  name: string
  phone: string | null
  id_number: string | null
  organization: string | null
  purpose: string
  host_staff_id: number | null
  host?: { id: number; name: string } | null
  people_count: number
  badge_no: string | null
  check_in_at: string
  check_out_at: string | null
  note: string | null
  created_at: string
}

export interface VisitorInput {
  name: string
  phone: string | null
  id_number: string | null
  organization: string | null
  purpose: string
  host_staff_id: number | null
  people_count: number
  badge_no: string | null
  note: string | null
  check_in_at?: string
  check_out_at?: string | null
}

export interface CheckInInput {
  people_count: number
  id_number: string | null
  badge_no: string | null
}

export interface VisitorSummary {
  today: number
  people_today: number
  on_site: number
  this_month: number
}

export const frontDeskService = {
  staffOptions: () => apiGet<StaffOption[]>('/front-office/staff-options'),

  async appointments(query: PaginatedQuery & { when?: AppointmentWhen }): Promise<PaginatedResult<Appointment>> {
    const result = await apiGetWithMeta<Appointment[]>('/appointments', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter, when: query.when || undefined },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  appointmentSummary: () => apiGet<AppointmentSummary>('/appointments/summary'),
  createAppointment: (input: AppointmentInput) => apiPost<Appointment>('/appointments', input),
  updateAppointment: (id: number, input: Partial<AppointmentInput> & { status?: AppointmentStatus }) => apiPut<Appointment>(`/appointments/${id}`, input),
  removeAppointment: (id: number) => apiDelete(`/appointments/${id}`),
  checkIn: (id: number, input: CheckInInput) => apiPost<Visitor>(`/appointments/${id}/check-in`, input),

  async visitors(query: PaginatedQuery & { date?: string; onSite?: boolean }): Promise<PaginatedResult<Visitor>> {
    const result = await apiGetWithMeta<Visitor[]>('/visitors', {
      params: {
        page: query.page,
        per_page: query.per_page,
        search: query.search,
        sort: query.sort,
        filter: query.filter,
        date: query.date || undefined,
        on_site: query.onSite ? 1 : undefined,
      },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  visitorSummary: () => apiGet<VisitorSummary>('/visitors/summary'),
  createVisitor: (input: VisitorInput) => apiPost<Visitor>('/visitors', input),
  updateVisitor: (id: number, input: Partial<VisitorInput>) => apiPut<Visitor>(`/visitors/${id}`, input),
  removeVisitor: (id: number) => apiDelete(`/visitors/${id}`),
  checkOut: (id: number) => apiPost<Visitor>(`/visitors/${id}/check-out`, {}),
}

import { apiDelete, apiGetWithMeta, apiPost, apiPostWithMeta } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { Position } from '@/services/positions'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type StaffStatus = 'active' | 'inactive'

export interface Staff {
  id: number
  employee_code: string
  first_name: string
  last_name: string
  full_name: string
  other_name: string | null
  gender: string | null
  date_of_birth: string | null
  birth_place: string | null
  national_id: string | null
  national_id_photo_url: string | null
  email: string | null
  phone: string
  house_no: string | null
  street_no: string | null
  village_code: string | null
  facebook: string | null
  telegram: string | null
  other_contact: string | null
  photo_url: string | null
  /** Server-generated avatar fallback color — see StaffController::profileColorFor() on the backend. Never user-editable. */
  profile_color: string | null
  hire_date: string | null
  status: StaffStatus
  position: Position | null
  user: { id: number; email: string | null; phone: string | null; status: string } | null
  created_at: string
}

export interface StaffInput {
  /** Omitted on update when the admin isn't replacing the photo. */
  photo?: File
  national_id_photo?: File
  employee_code: string
  first_name: string
  last_name: string
  other_name: string
  gender: string
  date_of_birth: string
  birth_place: string
  national_id: string
  phone: string
  email: string
  house_no: string
  street_no: string
  village_code: string
  facebook: string
  telegram: string
  other_contact: string
  position_id: number | null
  hire_date: string
  status: StaffStatus
}

export interface StaffCreated {
  staff: Staff
  /** Shown once, right after creation — see UserProvisioningService on the backend. */
  temporaryPassword: string | null
}

/**
 * Flat fields only (no nested arrays, unlike Student's guardians/educations)
 * — see students.ts's toFormData() for the bracket-notation technique this
 * would need if Staff ever grows a nested collection of its own.
 */
function toFormData(input: StaffInput, methodOverride?: 'PUT'): FormData {
  const form = new FormData()

  if (input.photo) form.append('photo', input.photo)
  if (input.national_id_photo) form.append('national_id_photo', input.national_id_photo)

  form.append('employee_code', input.employee_code)
  form.append('first_name', input.first_name)
  form.append('last_name', input.last_name)
  if (input.other_name) form.append('other_name', input.other_name)
  if (input.gender) form.append('gender', input.gender)
  if (input.date_of_birth) form.append('date_of_birth', input.date_of_birth)
  if (input.birth_place) form.append('birth_place', input.birth_place)
  if (input.national_id) form.append('national_id', input.national_id)
  form.append('phone', input.phone)
  if (input.email) form.append('email', input.email)
  if (input.house_no) form.append('house_no', input.house_no)
  if (input.street_no) form.append('street_no', input.street_no)
  if (input.village_code) form.append('village_code', input.village_code)
  if (input.facebook) form.append('facebook', input.facebook)
  if (input.telegram) form.append('telegram', input.telegram)
  if (input.other_contact) form.append('other_contact', input.other_contact)
  if (input.position_id) form.append('position_id', String(input.position_id))
  if (input.hire_date) form.append('hire_date', input.hire_date)
  form.append('status', input.status)

  if (methodOverride) form.append('_method', methodOverride)

  return form
}

export const staffService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Staff>> {
    const result = await apiGetWithMeta<Staff[]>('/staff', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<Staff>(`/staff/${id}`).then((r) => r.data),

  async create(input: StaffInput): Promise<StaffCreated> {
    const result = await apiPostWithMeta<Staff>('/staff', toFormData(input))
    return { staff: result.data, temporaryPassword: (result.meta?.temporary_password as string) ?? null }
  },

  // File uploads can't ride a native PUT — same `_method` override trick as
  // studentsService.update().
  update: (id: number, input: StaffInput) => apiPost<Staff>(`/staff/${id}`, toFormData(input, 'PUT')),

  remove: (id: number) => apiDelete(`/staff/${id}`),
}

import { apiDelete, apiGet, apiPost, apiPut } from '@/services/http'

/** HRM > Staff > Education (docs/hrm/staff-education.md): a staff member's schools, many each. */
export const EDUCATION_LEVELS = ['high_school', 'diploma', 'associate', 'bachelor', 'master', 'doctorate', 'other'] as const
export type EducationLevel = (typeof EDUCATION_LEVELS)[number]

export interface StaffEducation {
  id: number
  staff_id: number
  level: EducationLevel
  institution: string
  major: string | null
  country: string | null
  start_year: number | null
  /** Empty = still studying there. */
  end_year: number | null
  note: string | null
}

export type StaffEducationInput = Omit<StaffEducation, 'id' | 'staff_id'>

export const staffEducationService = {
  list: (staffId: number) => apiGet<StaffEducation[]>(`/staff/${staffId}/educations`),
  create: (staffId: number, input: StaffEducationInput) => apiPost<StaffEducation>(`/staff/${staffId}/educations`, input),
  update: (staffId: number, id: number, input: StaffEducationInput) => apiPut<StaffEducation>(`/staff/${staffId}/educations/${id}`, input),
  remove: (staffId: number, id: number) => apiDelete(`/staff/${staffId}/educations/${id}`),
}

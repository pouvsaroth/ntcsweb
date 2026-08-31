import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface StudentGuardian {
  id?: number
  guardian_name: string
  guardian_type: string
  address: string
  phone: string
  email: string
  remark: string
}

export interface StudentEducation {
  id?: number
  school_name: string
  address: string
  start_date: string
  end_date: string
  skill: string
  detail: string
}

export type StudentStatus = 'active' | 'graduated' | 'withdrawn' | 'inactive'

export interface Student {
  id: number
  student_code: string
  first_name: string
  last_name: string
  full_name: string
  english_name: string | null
  date_of_birth: string | null
  gender: string | null
  email: string | null
  phone: string | null
  house_no: string | null
  street_no: string | null
  village_code: string | null
  other_address: string | null
  facebook: string | null
  telegram: string | null
  photo_url: string | null
  enrollment_date: string | null
  status: StudentStatus
  /** Present on the list endpoint. */
  guardians_count?: number
  educations_count?: number
  /** Present on `show`/`create`/`update`; absent (not empty) on the list endpoint. */
  guardians?: StudentGuardian[]
  educations?: StudentEducation[]
  created_at: string
}

export interface StudentInput {
  /** Omitted on update when the admin isn't replacing the photo. */
  photo?: File
  first_name: string
  last_name: string
  english_name: string
  date_of_birth: string
  gender: string
  email: string
  phone: string
  house_no: string
  street_no: string
  village_code: string
  other_address: string
  facebook: string
  telegram: string
  enrollment_date: string
  status: StudentStatus
  guardians: StudentGuardian[]
  educations: StudentEducation[]
}

/**
 * Nested arrays travel as bracket-notation FormData keys
 * (`guardians[0][guardian_name]`, ...), which PHP parses back into the same
 * nested array shape automatically — same technique as aboutPage.ts.
 */
function toFormData(input: StudentInput, methodOverride?: 'PUT'): FormData {
  const form = new FormData()

  if (input.photo) form.append('photo', input.photo)
  form.append('first_name', input.first_name)
  form.append('last_name', input.last_name)
  if (input.english_name) form.append('english_name', input.english_name)
  if (input.date_of_birth) form.append('date_of_birth', input.date_of_birth)
  if (input.gender) form.append('gender', input.gender)
  if (input.email) form.append('email', input.email)
  if (input.phone) form.append('phone', input.phone)
  if (input.house_no) form.append('house_no', input.house_no)
  if (input.street_no) form.append('street_no', input.street_no)
  if (input.village_code) form.append('village_code', input.village_code)
  if (input.other_address) form.append('other_address', input.other_address)
  if (input.facebook) form.append('facebook', input.facebook)
  if (input.telegram) form.append('telegram', input.telegram)
  if (input.enrollment_date) form.append('enrollment_date', input.enrollment_date)
  form.append('status', input.status)

  input.guardians.forEach((guardian, index) => {
    form.append(`guardians[${index}][guardian_name]`, guardian.guardian_name)
    form.append(`guardians[${index}][guardian_type]`, guardian.guardian_type)
    if (guardian.address) form.append(`guardians[${index}][address]`, guardian.address)
    form.append(`guardians[${index}][phone]`, guardian.phone)
    if (guardian.email) form.append(`guardians[${index}][email]`, guardian.email)
    if (guardian.remark) form.append(`guardians[${index}][remark]`, guardian.remark)
  })

  input.educations.forEach((education, index) => {
    form.append(`educations[${index}][school_name]`, education.school_name)
    form.append(`educations[${index}][address]`, education.address)
    form.append(`educations[${index}][start_date]`, education.start_date)
    if (education.end_date) form.append(`educations[${index}][end_date]`, education.end_date)
    form.append(`educations[${index}][skill]`, education.skill)
    form.append(`educations[${index}][detail]`, education.detail)
  })

  if (methodOverride) form.append('_method', methodOverride)

  return form
}

export const studentsService = {
  /**
   * `page`/`per_page` are optional here (unlike usePaginatedResource's own
   * PaginatedQuery) so a simple one-off search — e.g. EnrollmentForm's
   * student picker, which doesn't paginate at all — doesn't have to invent
   * values for fields it has no use for.
   */
  async list(query: Partial<PaginatedQuery> = {}): Promise<PaginatedResult<Student>> {
    const result = await apiGetWithMeta<Student[]>('/students', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<Student>(`/students/${id}`).then((r) => r.data),

  create: (input: StudentInput) => apiPost<Student>('/students', toFormData(input)),

  update: (id: number, input: StudentInput) => apiPost<Student>(`/students/${id}`, toFormData(input, 'PUT')),
}

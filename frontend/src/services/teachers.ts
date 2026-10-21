import { apiDelete, apiDownload, apiGet, apiPost, apiPut } from '@/services/http'

/**
 * HRM > Teacher (docs/hrm/teachers.md). A teacher is a Staff member whose
 * position carries the Teacher role; profile and employment stay on Staff.
 */

export interface Teacher {
  id: number
  name: string
  employee_code: string | null
  position: string | null
  phone: string | null
}

export interface TeacherOptions {
  classes: { id: number; name: string; academic_year_id: number | null; academic_year: string | null; grade_level: string | null }[]
  subjects: { id: number; title: string }[]
  academic_years: { id: number; name: string; is_current: boolean }[]
}

export const QUALIFICATION_TYPES = ['degree', 'certificate', 'skill'] as const
export const QUALIFICATION_LEVELS = ['high_school', 'diploma', 'bachelor', 'master', 'doctorate', 'other'] as const
export interface Qualification {
  id: number
  staff_id: number
  type: (typeof QUALIFICATION_TYPES)[number]
  level: (typeof QUALIFICATION_LEVELS)[number] | null
  title: string
  institution: string | null
  year: number | null
  note: string | null
}
export type QualificationInput = Omit<Qualification, 'id'>

export interface Assignment {
  id: number
  staff_id: number
  teacher: string | null
  class_id: number
  class: string | null
  book_id: number | null
  subject: string | null
  academic_year_id: number | null
  academic_year: string | null
  hours_per_week: number | null
}
export interface AssignmentInput {
  staff_id: number
  class_id: number
  book_id: number | null
  academic_year_id: number | null
  hours_per_week: number | null
}

export interface Homeroom {
  class_id: number
  class: string
  academic_year: string | null
  grade_level: string | null
  homeroom_staff_id: number | null
  homeroom: string | null
}

export interface TeachingLoad {
  staff_id: number
  name: string
  employee_code: string | null
  classes: string[]
  scheduled_hours: number
  assigned_hours: number
  subjects: number
  homeroom: string[]
}

export interface Unavailability {
  id: number
  staff_id: number
  kind: 'weekly' | 'dates'
  day_of_week: number | null
  from_date: string | null
  to_date: string | null
  start_time: string | null
  end_time: string | null
  reason: string | null
}
export type UnavailabilityInput = Omit<Unavailability, 'id'>
export interface Availability {
  unavailable: Unavailability[]
  leave: { id: number; from_date: string; to_date: string; days: number | null; reason: string | null }[]
  clashes: { slot_id: number; class: string; day_of_week: number; start_time: string; end_time: string }[]
}

export interface Substitution {
  id: number
  date: string
  start_time: string | null
  end_time: string | null
  class_id: number
  class: string | null
  absent_staff_id: number
  absent: string | null
  substitute_staff_id: number
  substitute: string | null
  subject: string | null
  reason: string | null
}
export interface SubstitutionCandidates {
  date: string
  start_time: string | null
  end_time: string | null
  class_teachers: { id: number; name: string; employee_code: string | null; away: 'leave' | 'unavailable' | null }[]
  available: { id: number; name: string; employee_code: string | null }[]
  not_available: { id: number; name: string; employee_code: string | null; why: string; busy_class: string | null }[]
}
export interface SubstitutionInput {
  class_id: number
  absent_staff_id: number
  substitute_staff_id: number
  date: string
  start_time: string | null
  end_time: string | null
  book_id: number | null
  reason: string | null
}

export const DOCUMENT_TYPES = ['contract', 'certificate', 'id_card', 'cv', 'other'] as const
export interface TeacherDocument {
  id: number
  staff_id: number
  teacher: string | null
  type: (typeof DOCUMENT_TYPES)[number]
  title: string
  file_name: string
  mime: string | null
  size: number
  expires_on: string | null
  expired: boolean
  note: string | null
  uploaded_at: string | null
}

const clean = <T extends object>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== null && v !== undefined && v !== ''))

export const teachersService = {
  list: () => apiGet<Teacher[]>('/teachers'),
  options: () => apiGet<TeacherOptions>('/teachers/options'),

  qualifications: (staffId: number) =>
    apiGet<{ qualifications: Qualification[]; subjects: { id: number; title: string }[] }>('/teachers/qualifications', { params: { staff_id: staffId } }),
  createQualification: (input: QualificationInput) => apiPost<Qualification>('/teachers/qualifications', input),
  updateQualification: (id: number, input: QualificationInput) => apiPut<Qualification>(`/teachers/qualifications/${id}`, input),
  removeQualification: (id: number) => apiDelete(`/teachers/qualifications/${id}`),
  saveSubjects: (staffId: number, bookIds: number[]) => apiPut('/teachers/subjects', { staff_id: staffId, book_ids: bookIds }),

  assignments: (filter: { staff_id?: number; class_id?: number; academic_year_id?: number }) =>
    apiGet<Assignment[]>('/teachers/assignments', { params: clean(filter) }),
  createAssignment: (input: AssignmentInput) => apiPost<Assignment>('/teachers/assignments', input),
  updateAssignment: (id: number, input: AssignmentInput) => apiPut<Assignment>(`/teachers/assignments/${id}`, input),
  removeAssignment: (id: number) => apiDelete(`/teachers/assignments/${id}`),

  homerooms: (academicYearId?: number) => apiGet<Homeroom[]>('/teachers/homerooms', { params: clean({ academic_year_id: academicYearId }) }),
  saveHomeroom: (classId: number, staffId: number | null) => apiPut(`/teachers/homerooms/${classId}`, { staff_id: staffId }),

  load: (academicYearId?: number) => apiGet<TeachingLoad[]>('/teachers/load', { params: clean({ academic_year_id: academicYearId }) }),

  availability: (staffId: number) => apiGet<Availability>('/teachers/availability', { params: { staff_id: staffId } }),
  createUnavailability: (input: UnavailabilityInput) => apiPost<Unavailability>('/teachers/unavailability', input),
  updateUnavailability: (id: number, input: UnavailabilityInput) => apiPut<Unavailability>(`/teachers/unavailability/${id}`, input),
  removeUnavailability: (id: number) => apiDelete(`/teachers/unavailability/${id}`),

  substitutions: (from: string, to: string) => apiGet<Substitution[]>('/teachers/substitutions', { params: { from, to } }),
  candidates: (classId: number, date: string, start?: string, end?: string) =>
    apiGet<SubstitutionCandidates>('/teachers/substitutions/candidates', { params: clean({ class_id: classId, date, start_time: start, end_time: end }) }),
  createSubstitution: (input: SubstitutionInput) => apiPost<Substitution>('/teachers/substitutions', input),
  removeSubstitution: (id: number) => apiDelete(`/teachers/substitutions/${id}`),

  documents: (staffId?: number) => apiGet<TeacherDocument[]>('/teachers/documents', { params: clean({ staff_id: staffId }) }),
  uploadDocument(input: { staff_id: number; type: string; title: string; expires_on: string | null; note: string | null; file: File }): Promise<TeacherDocument> {
    const form = new FormData()
    for (const [key, value] of Object.entries(input)) {
      if (value !== null && value !== undefined && value !== '') form.append(key, value instanceof File ? value : String(value))
    }
    return apiPost<TeacherDocument>('/teachers/documents', form)
  },
  downloadDocument: (doc: TeacherDocument) => apiDownload(`/teachers/documents/${doc.id}/file`, doc.file_name),
  removeDocument: (id: number) => apiDelete(`/teachers/documents/${id}`),
}

import { apiGetWithMeta, apiPost } from '@/services/http'

export interface SchoolDocuments {
  school_regulation_url: string | null
  student_attendance_policy_url: string | null
}

export interface SchoolDocumentsInput {
  school_regulation?: File
  student_attendance_policy?: File
}

function toFormData(input: SchoolDocumentsInput): FormData {
  const form = new FormData()
  if (input.school_regulation) form.append('school_regulation', input.school_regulation)
  if (input.student_attendance_policy) form.append('student_attendance_policy', input.student_attendance_policy)
  return form
}

/** Admin upload for the two fixed public documents. See SchoolDocumentsContent on the backend. */
export const schoolDocumentsService = {
  get: () => apiGetWithMeta<SchoolDocuments>('/settings/documents').then((r) => r.data),
  save: (input: SchoolDocumentsInput) => apiPost<SchoolDocuments>('/settings/documents', toFormData(input)),
}

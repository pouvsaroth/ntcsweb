import { apiDelete, apiDownload, apiGet, apiPost } from '@/services/http'

/**
 * HRM > Staff > Documents (docs/hrm/staff-profile-tabs.md): a staff member's
 * private personal / HR files. Teaching documents live in Teachers > Documents.
 */
export const STAFF_DOCUMENT_TYPES = ['id_card', 'passport', 'birth_certificate', 'family_book', 'cv', 'contract', 'certificate', 'work_permit', 'medical', 'other'] as const

export interface StaffDocument {
  id: number
  type: string
  title: string
  document_no: string | null
  issued_on: string | null
  expires_on: string | null
  expired: boolean
  note: string | null
  file_name: string
  mime: string | null
  size: number
  uploaded_at: string | null
}

export interface StaffDocumentInput {
  type: string
  title: string
  document_no: string | null
  issued_on: string | null
  expires_on: string | null
  note: string | null
  /** Required for a new document; on edit, only to replace the file. */
  file: File | null
}

function toForm(input: StaffDocumentInput): FormData {
  const form = new FormData()
  for (const [key, value] of Object.entries(input)) {
    // Empty fields are sent as '' so an edit can clear them.
    if (value instanceof File) form.append(key, value)
    else if (key !== 'file') form.append(key, value ?? '')
  }
  return form
}

export const staffDocumentsService = {
  list: (staffId: number) => apiGet<StaffDocument[]>(`/staff/${staffId}/documents`),
  create: (staffId: number, input: StaffDocumentInput) => apiPost<StaffDocument>(`/staff/${staffId}/documents`, toForm(input)),
  update: (staffId: number, id: number, input: StaffDocumentInput) => apiPost<StaffDocument>(`/staff/${staffId}/documents/${id}`, toForm(input)),
  download: (staffId: number, doc: StaffDocument) => apiDownload(`/staff/${staffId}/documents/${doc.id}/file`, doc.file_name),
  remove: (staffId: number, id: number) => apiDelete(`/staff/${staffId}/documents/${id}`),
}

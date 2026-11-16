import { apiDelete, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import type { CardField, CardFor } from '@/utils/staffCard'

/** HRM > Staff cards: one card design — size in mm, a photo per side and the fields placed on each side. */
export interface StaffCardDesign {
  id: number
  /** HRM > Staff cards or Students > Student cards. */
  card_for: CardFor
  name: string
  width_mm: number
  height_mm: number
  front_background_url: string | null
  back_background_url: string | null
  /** Signature / stamp image for the Signature field. */
  signature_url: string | null
  /** For the School logo field. */
  school_logo_url: string | null
  front_fields: CardField[]
  back_fields: CardField[]
  updated_at: string | null
}

/** Students > Student cards > print: a student with what their card can show. */
export interface CardStudent {
  id: number
  student_code: string
  full_name: string
  english_name: string | null
  gender: string | null
  date_of_birth: string | null
  phone: string | null
  photo_url: string | null
  enrollment_date: string | null
  /** From their current enrollment, or the picked class. */
  class_name: string | null
  course: string | null
  program: string | null
  academic_year: string | null
  /** Their first guardian's phone. */
  guardian_phone: string | null
}

export interface StaffCardDesignInput {
  /** Create only. */
  card_for?: CardFor
  name?: string
  width_mm?: number
  height_mm?: number
  front_fields?: CardField[]
  back_fields?: CardField[]
  front_background?: File | null
  back_background?: File | null
  signature?: File | null
  remove_front_background?: boolean
  remove_back_background?: boolean
  remove_signature?: boolean
}

function toFormData(input: StaffCardDesignInput, methodOverride?: 'PUT'): FormData {
  const form = new FormData()
  if (input.card_for !== undefined) form.append('card_for', input.card_for)
  if (input.name !== undefined) form.append('name', input.name)
  if (input.width_mm !== undefined) form.append('width_mm', String(input.width_mm))
  if (input.height_mm !== undefined) form.append('height_mm', String(input.height_mm))
  if (input.front_fields !== undefined) form.append('front_fields', JSON.stringify(input.front_fields))
  if (input.back_fields !== undefined) form.append('back_fields', JSON.stringify(input.back_fields))
  for (const key of ['front_background', 'back_background', 'signature'] as const) {
    const file = input[key]
    if (file) form.append(key, file)
  }
  for (const key of ['remove_front_background', 'remove_back_background', 'remove_signature'] as const) {
    if (input[key]) form.append(key, '1')
  }
  if (methodOverride) form.append('_method', methodOverride)
  return form
}

export const staffCardDesignsService = {
  async list(query: PaginatedQuery, cardFor: CardFor): Promise<PaginatedResult<StaffCardDesign>> {
    const result = await apiGetWithMeta<StaffCardDesign[]>('/staff-card-designs', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, card_for: cardFor },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  get: (id: number) => apiGetWithMeta<StaffCardDesign>(`/staff-card-designs/${id}`).then((r) => r.data),
  create: (input: StaffCardDesignInput) => apiPost<StaffCardDesign>('/staff-card-designs', toFormData(input)),
  update: (id: number, input: StaffCardDesignInput) => apiPost<StaffCardDesign>(`/staff-card-designs/${id}`, toFormData(input, 'PUT')),
  remove: (id: number) => apiDelete(`/staff-card-designs/${id}`),
  /** Students to print: by class and/or a search (code or name); at most 500. */
  students: (params: { class_id?: number; search?: string }) => apiGetWithMeta<CardStudent[]>('/student-cards/students', { params }).then((r) => r.data),
}

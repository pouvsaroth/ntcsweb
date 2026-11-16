import { apiDelete, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import type { CardField } from '@/utils/staffCard'

/** HRM > Staff cards: one card design — size in mm, a photo per side and the fields placed on each side. */
export interface StaffCardDesign {
  id: number
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

export interface StaffCardDesignInput {
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
  async list(query: PaginatedQuery): Promise<PaginatedResult<StaffCardDesign>> {
    const result = await apiGetWithMeta<StaffCardDesign[]>('/staff-card-designs', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  get: (id: number) => apiGetWithMeta<StaffCardDesign>(`/staff-card-designs/${id}`).then((r) => r.data),
  create: (input: StaffCardDesignInput) => apiPost<StaffCardDesign>('/staff-card-designs', toFormData(input)),
  update: (id: number, input: StaffCardDesignInput) => apiPost<StaffCardDesign>(`/staff-card-designs/${id}`, toFormData(input, 'PUT')),
  remove: (id: number) => apiDelete(`/staff-card-designs/${id}`),
}

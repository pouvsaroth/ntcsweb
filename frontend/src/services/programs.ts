import { apiDelete, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type ProgramLevel = 'beginner' | 'intermediate' | 'advanced'

export interface Program {
  id: number
  title: string
  subtitle: string | null
  category: string
  level: ProgramLevel
  duration_label: string | null
  fee: number | null
  description: string | null
  image_url: string | null
  is_featured: boolean
  sort_order: number
  status: 'active' | 'inactive'
  created_at: string
}

export interface ProgramInput {
  /** Omitted on update when the admin isn't replacing the image. */
  image?: File
  title: string
  subtitle: string
  category: string
  level: ProgramLevel
  duration_label: string
  /** Empty string means "no fee set" — see toFormData() below. */
  fee: string
  description: string
  is_featured: boolean
  sort_order: number
  status: 'active' | 'inactive'
}

/**
 * A real file can't travel as JSON, so every write here sends
 * multipart/form-data — see homeSlides.ts's toFormData() for why an update
 * still goes through POST with a `_method=PUT` override rather than a real
 * HTTP PUT.
 */
function toFormData(input: ProgramInput, methodOverride?: 'PUT'): FormData {
  const form = new FormData()

  if (input.image) form.append('image', input.image)
  form.append('title', input.title)
  if (input.subtitle) form.append('subtitle', input.subtitle)
  form.append('category', input.category)
  form.append('level', input.level)
  if (input.duration_label) form.append('duration_label', input.duration_label)
  if (input.fee.trim()) form.append('fee', input.fee.trim())
  if (input.description) form.append('description', input.description)
  form.append('is_featured', input.is_featured ? '1' : '0')
  form.append('sort_order', String(input.sort_order))
  form.append('status', input.status)
  if (methodOverride) form.append('_method', methodOverride)

  return form
}

export const programsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Program>> {
    const result = await apiGetWithMeta<Program[]>('/programs', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create(input: ProgramInput) {
    return apiPost<Program>('/programs', toFormData(input))
  },

  update(id: number, input: ProgramInput) {
    return apiPost<Program>(`/programs/${id}`, toFormData(input, 'PUT'))
  },

  remove(id: number) {
    return apiDelete(`/programs/${id}`)
  },
}

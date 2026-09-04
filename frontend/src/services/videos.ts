import { apiDelete, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type VideoStatus = 'active' | 'inactive'

export interface Video {
  id: number
  course_package_id: number
  course_package: { id: number; name: string } | null
  title: string
  description: string | null
  video_url: string
  thumbnail_url: string | null
  embed_url: string | null
  sort_order: number
  status: VideoStatus
  created_at: string
}

export interface VideoInput {
  course_package_id: number
  title: string
  description: string
  video_url: string
  sort_order: number
  status: VideoStatus
  /** Omitted when the admin isn't replacing YouTube's own thumbnail. */
  thumbnail?: File
}

/**
 * A real file can't travel as JSON — every write here sends multipart
 * form-data, same convention as CoursePackage's own thumbnail upload. See
 * coursePackages.ts's toFormData() for why update rides a POST with a
 * `_method` override rather than a real PUT.
 */
function toFormData(input: VideoInput, methodOverride?: 'PUT'): FormData {
  const form = new FormData()

  form.append('course_package_id', String(input.course_package_id))
  form.append('title', input.title)
  if (input.description.trim()) form.append('description', input.description.trim())
  form.append('video_url', input.video_url)
  if (input.thumbnail) form.append('thumbnail', input.thumbnail)
  form.append('sort_order', String(input.sort_order))
  form.append('status', input.status)
  if (methodOverride) form.append('_method', methodOverride)

  return form
}

export const videosService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Video>> {
    const result = await apiGetWithMeta<Video[]>('/videos', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: VideoInput) => apiPost<Video>('/videos', toFormData(input)),
  update: (id: number, input: VideoInput) => apiPost<Video>(`/videos/${id}`, toFormData(input, 'PUT')),
  remove: (id: number) => apiDelete(`/videos/${id}`),
}

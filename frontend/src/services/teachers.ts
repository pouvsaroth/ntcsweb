import { apiGetWithMeta } from '@/services/http'

export interface Teacher {
  id: number
  name: string
  status: 'active' | 'inactive'
}

export const teachersService = {
  /** All active teachers, for a select dropdown — the table is small, no pagination UI needed here. */
  async listAll(): Promise<Teacher[]> {
    const result = await apiGetWithMeta<Teacher[]>('/teachers', { params: { per_page: 200, filter: { status: 'active' } } })
    return result.data
  },
}

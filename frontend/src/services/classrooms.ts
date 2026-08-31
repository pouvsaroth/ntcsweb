import { apiGetWithMeta } from '@/services/http'

export interface Classroom {
  id: number
  name: string
  status: 'active' | 'inactive'
}

export const classroomsService = {
  /** All active classrooms, for a select dropdown — the table is small, no pagination UI needed here. */
  async listAll(): Promise<Classroom[]> {
    const result = await apiGetWithMeta<Classroom[]>('/classrooms', { params: { per_page: 200, filter: { status: 'active' } } })
    return result.data
  },
}

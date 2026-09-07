import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import type { StudentFeedback } from '@/services/myStudentFeedback'

/** Admin queue — see StudentFeedback.vue under Communication. */
export const studentFeedbackService = {
  async list(query: Partial<PaginatedQuery> = {}): Promise<PaginatedResult<StudentFeedback>> {
    const result = await apiGetWithMeta<StudentFeedback[]>('/student-feedback', {
      params: { page: query.page ?? 1, per_page: query.per_page ?? 100, sort: query.sort ?? '-created_at', filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  reply: (id: number, body: string) => apiPost<StudentFeedback>(`/student-feedback/${id}/reply`, { body }),
}

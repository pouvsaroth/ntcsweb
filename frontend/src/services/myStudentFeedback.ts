import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type StudentFeedbackType = 'request' | 'comment'
export type StudentFeedbackTopic = 'school' | 'teacher'
export type StudentFeedbackStatus = 'open' | 'replied'

export interface StudentFeedbackReply {
  id: number
  body: string
  user?: { id: number; name: string }
  created_at: string
}

export interface StudentFeedbackStudent {
  id: number
  student_code: string
  name: string
}

export interface StudentFeedback {
  id: number
  student?: StudentFeedbackStudent
  type: StudentFeedbackType
  topic: StudentFeedbackTopic
  teacher?: { id: number; name: string } | null
  subject: string
  message: string
  status: StudentFeedbackStatus
  replies: StudentFeedbackReply[]
  created_at: string
}

export interface StudentFeedbackInput {
  type: StudentFeedbackType
  topic: StudentFeedbackTopic
  teacher_id?: number | null
  subject: string
  message: string
}

export interface StudentFeedbackTeacherOption {
  id: number
  name: string
}

/** Student self-service — own requests/comments only, scoped server-side. See PublicUserMenu's "My Request"/"Comment" entries. */
export const myStudentFeedbackService = {
  async list(query: Partial<PaginatedQuery> = {}): Promise<PaginatedResult<StudentFeedback>> {
    const result = await apiGetWithMeta<StudentFeedback[]>('/my-feedback', {
      params: { page: query.page ?? 1, per_page: query.per_page ?? 100, sort: query.sort ?? '-created_at', filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  submit: (input: StudentFeedbackInput) => apiPost<StudentFeedback>('/my-feedback', input),

  reply: (id: number, body: string) => apiPost<StudentFeedback>(`/my-feedback/${id}/reply`, { body }),

  teachers: () => apiGetWithMeta<StudentFeedbackTeacherOption[]>('/my-feedback/teachers').then((r) => r.data),
}

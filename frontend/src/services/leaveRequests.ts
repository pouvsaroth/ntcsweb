import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type LeaveRequestStatus = 'pending' | 'approved' | 'rejected'

export interface LeaveRequestAttachment {
  id: number
  file_name: string
  mime_type: string | null
  url: string
  created_at: string
}

export interface LeaveRequestStudent {
  id: number
  student_code: string
  name: string
}

export interface LeaveRequest {
  id: number
  student?: LeaveRequestStudent
  from_date: string
  to_date: string
  from_time: string | null
  to_time: string | null
  reason: string
  status: LeaveRequestStatus
  decision_reason: string | null
  decided_by?: string | null
  decided_at: string | null
  attachments: LeaveRequestAttachment[]
  created_at: string
}

export interface LeaveRequestInput {
  from_date: string
  to_date: string
  from_time: string | null
  to_time: string | null
  reason: string
  attachments: File[]
}

function toFormData(input: LeaveRequestInput): FormData {
  const form = new FormData()
  form.append('from_date', input.from_date)
  form.append('to_date', input.to_date)
  if (input.from_time) form.append('from_time', input.from_time)
  if (input.to_time) form.append('to_time', input.to_time)
  form.append('reason', input.reason)
  input.attachments.forEach((file) => form.append('attachments[]', file))
  return form
}

/** Student self-service — own requests only, scoped server-side. See PublicUserMenu's "Ask for Permission" entry. */
export const myLeaveRequestsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<LeaveRequest>> {
    const result = await apiGetWithMeta<LeaveRequest[]>('/my-leave-requests', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  submit: (input: LeaveRequestInput) => apiPost<LeaveRequest>('/my-leave-requests', toFormData(input)),
}

/** Admin approve/reject queue — see the "Leave Requests" page under Settings. */
export const leaveRequestsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<LeaveRequest>> {
    const result = await apiGetWithMeta<LeaveRequest[]>('/leave-requests', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<LeaveRequest>(`/leave-requests/${id}`).then((r) => r.data),
  approve: (id: number) => apiPost<LeaveRequest>(`/leave-requests/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<LeaveRequest>(`/leave-requests/${id}/reject`, { reason }),
}

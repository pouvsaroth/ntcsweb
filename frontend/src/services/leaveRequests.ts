import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import type { ApprovalFlowProgress } from '@/services/approvalFlows'

export type LeaveRequestStatus = 'pending' | 'approved' | 'rejected'

/** A staff request's full day, or a morning/afternoon half of one date. */
export type LeaveDayPart = 'full' | 'morning' | 'afternoon'

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

export interface LeaveRequestStaff {
  id: number
  name: string
  employee_code: string | null
}

export interface LeaveRequest {
  id: number
  student?: LeaveRequestStudent | null
  staff?: LeaveRequestStaff | null
  /** Staff requests only (HRM > Leave Management); null on a student's and on older staff ones. */
  leave_type?: { id: number; code: string; name: string; color: string | null } | null
  day_part: LeaveDayPart | null
  /** Working days it takes off the balance, in half days — null when not counted. */
  days: number | null
  from_date: string
  to_date: string
  from_time: string | null
  to_time: string | null
  reason: string
  status: LeaveRequestStatus
  /** Approvals queue only — see ApprovalFlowProgress. */
  approval_flow?: ApprovalFlowProgress | null
  decision_reason: string | null
  decided_by?: string | null
  decided_at: string | null
  attachments: LeaveRequestAttachment[]
  created_at: string
}

export interface LeaveRequestInput {
  /** Staff only. */
  leave_type_id?: number | null
  /** Staff only. */
  day_part?: LeaveDayPart | null
  from_date: string
  to_date: string
  from_time: string | null
  to_time: string | null
  reason: string
  attachments: File[]
}

function toFormData(input: LeaveRequestInput): FormData {
  const form = new FormData()
  if (input.leave_type_id) form.append('leave_type_id', String(input.leave_type_id))
  if (input.day_part) form.append('day_part', input.day_part)
  form.append('from_date', input.from_date)
  form.append('to_date', input.to_date)
  if (input.from_time) form.append('from_time', input.from_time)
  if (input.to_time) form.append('to_time', input.to_time)
  form.append('reason', input.reason)
  input.attachments.forEach((file) => form.append('attachments[]', file))
  return form
}

/** Student self-service — own requests only, scoped server-side. See AdminSidebar's "Ask for Permission" entry. */
export const myLeaveRequestsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<LeaveRequest>> {
    const result = await apiGetWithMeta<LeaveRequest[]>('/my-leave-requests', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  submit: (input: LeaveRequestInput) => apiPost<LeaveRequest>('/my-leave-requests', toFormData(input)),

  /** A staff member's leave types with this year's balance — empty for a student, or before any types are set up. */
  types: (year?: number) => apiGetWithMeta<MyLeaveType[]>('/my-leave-requests/types', { params: year ? { year } : {} }).then((r) => r.data),

  /** Working days these dates take for the signed-in staff member. */
  quote: (params: { from_date: string; to_date: string; day_part?: LeaveDayPart }) =>
    apiGetWithMeta<{ days: number }>('/my-leave-requests/quote', { params }).then((r) => r.data.days),
}

/** One leave type's year: total = entitlement + carried forward (minus what lapsed) + adjustments; available = total − used − pending. */
export interface LeaveBalance {
  policy_id: number
  entitlement: number
  carried_forward: number
  carried_expires_on: string | null
  carried_lapsed: number
  adjustments: number
  total: number
  used: number
  pending: number
  available: number
}

export interface MyLeaveType {
  id: number
  code: string
  name: string
  color: string | null
  allow_half_day: boolean
  requires_attachment: boolean
  /** Null: no policy applies, so this type isn't limited by a balance. */
  balance: LeaveBalance | null
}

/** Admin approve/reject queue — see the "Leave Requests" page under Settings. */
export const leaveRequestsService = {
  /** `approvalQueue`: the Approvals queue's view — a pending request of an item with an approval flow is only listed to the group it waits on. */
  /** `staffOnly` + `year`: HRM > Leave Management > Leave request's view — staff requests starting that year. */
  async list(query: PaginatedQuery, opts: { approvalQueue?: boolean; staffOnly?: boolean; year?: number } = {}): Promise<PaginatedResult<LeaveRequest>> {
    const result = await apiGetWithMeta<LeaveRequest[]>('/leave-requests', {
      params: {
        page: query.page,
        per_page: query.per_page,
        sort: query.sort,
        filter: query.filter,
        ...(opts.approvalQueue ? { approval_queue: '1' } : {}),
        ...(opts.staffOnly ? { requester: 'staff' } : {}),
        ...(opts.year ? { year: opts.year } : {}),
      },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<LeaveRequest>(`/leave-requests/${id}`).then((r) => r.data),
  approve: (id: number) => apiPost<LeaveRequest>(`/leave-requests/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<LeaveRequest>(`/leave-requests/${id}/reject`, { reason }),

  /** HR files one for a staff member — checked like their own, minus the notice period. */
  createForStaff: (staffId: number, input: LeaveRequestInput) => {
    const form = toFormData(input)
    form.append('staff_id', String(staffId))
    return apiPost<LeaveRequest>('/leave-requests', form)
  },
}

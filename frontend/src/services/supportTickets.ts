import { apiGet, apiPost } from '@/services/http'
import type { ChatAttachment } from '@/services/supportChat'

/** Support tickets to NewTech (docs/backoffice/stage-13-help-support.md, 13b). Own tickets only. */
export type TicketStatus = 'open' | 'in_progress' | 'resolved' | 'closed'
export type TicketPriority = 'low' | 'normal' | 'high' | 'urgent'

export interface TicketSummary {
  id: number
  /** T-00042 */
  number: string
  subject: string
  module: string | null
  module_label: string | null
  module_label_km: string | null
  priority: TicketPriority
  status: TicketStatus
  /** NewTech replies and changes not seen yet. */
  unread: number
  user_name: string
  last_reply_at: string | null
  resolved_at: string | null
  closed_at: string | null
  created_at: string
}

export interface TicketMessage {
  id: number
  sender: 'user' | 'staff'
  staff: string | null
  body: string | null
  /** A status change shown as a line in the thread. */
  event: { status: TicketStatus } | null
  attachments: ChatAttachment[]
  created_at: string
}

export interface TicketThread {
  ticket: TicketSummary
  messages: TicketMessage[]
}

export interface TicketOptions {
  modules: { code: string; label: string; label_km: string | null }[]
  priorities: TicketPriority[]
}

export interface NewTicket {
  subject: string
  module: string | null
  priority: TicketPriority
  body: string
  files: File[]
}

export const TICKET_PRIORITIES: TicketPriority[] = ['low', 'normal', 'high', 'urgent']

export const supportTicketService = {
  list: (status?: 'active' | 'done') => apiGet<TicketSummary[]>('/support-tickets', { params: { status } }),
  unread: () => apiGet<{ unread: number }>('/support-tickets/unread'),
  options: () => apiGet<TicketOptions>('/support-tickets/options'),
  show: (id: number) => apiGet<TicketThread>(`/support-tickets/${id}`),
  create: (data: NewTicket) => {
    const form = new FormData()
    form.append('subject', data.subject.trim())
    if (data.module) form.append('module', data.module)
    form.append('priority', data.priority)
    form.append('body', data.body.trim())
    data.files.forEach((f) => form.append('files[]', f))
    return apiPost<TicketSummary>('/support-tickets', form)
  },
  reply: (id: number, body: string, files: File[]) => {
    const form = new FormData()
    if (body.trim()) form.append('body', body.trim())
    files.forEach((f) => form.append('files[]', f))
    return apiPost<TicketThread>(`/support-tickets/${id}/messages`, form)
  },
  close: (id: number) => apiPost<TicketThread>(`/support-tickets/${id}/close`),
}

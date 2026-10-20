import { apiGet, apiPost } from '@/services/http'

/** The support chat with NewTech (docs/backoffice/stage-10-support-chat.md). */
export interface ChatAttachment {
  name: string
  mime: string
  size: number
  is_image: boolean
  /** Same-origin API path; the session cookie authorises it. */
  url: string
}

export interface ChatMessage {
  id: number
  sender: 'user' | 'staff'
  /** NewTech staff member's name on a reply. */
  staff: string | null
  body: string | null
  attachments: ChatAttachment[]
  created_at: string
}

export interface ChatState {
  chat: { id: number; status: 'open' | 'closed'; unread: number }
  messages: ChatMessage[]
}

/** Images, PDF, Word, Excel, CSV, text — as the server accepts. */
export const CHAT_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt'
export const CHAT_MAX_BYTES = 10 * 1024 * 1024
export const CHAT_MAX_FILES = 5

export const supportChatService = {
  show: (after?: number) => apiGet<ChatState>('/support-chat', { params: { after: after || undefined } }),
  unread: () => apiGet<{ unread: number }>('/support-chat/unread'),
  read: () => apiPost<{ unread: number }>('/support-chat/read'),
  send: (body: string, files: File[]) => {
    const form = new FormData()
    if (body.trim()) form.append('body', body.trim())
    files.forEach((f) => form.append('files[]', f))
    return apiPost<ChatMessage>('/support-chat', form)
  },
}

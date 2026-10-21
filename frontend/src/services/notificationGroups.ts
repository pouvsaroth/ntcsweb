import { apiDelete, apiGet, apiPost, apiPut } from '@/services/http'

/** Telegram notification groups (docs/backoffice/stage-11-telegram-notifications.md). */
export interface GroupMember {
  id: number
  name: string
  email: string | null
}

export interface NotificationGroup {
  id: number
  name: string
  linked: boolean
  telegram_title: string | null
  linked_at: string | null
  /** "/link CODE" to send in the Telegram group; null when none is waiting. */
  link_code: string | null
  link_code_expires_at: string | null
  users: GroupMember[]
}

export interface NotificationGroupsState {
  groups: NotificationGroup[]
  /** NewTech has set up its Telegram bot. */
  bot_ready: boolean
  bot_username: string | null
}

const base = '/settings/notification-groups'

export const notificationGroupsService = {
  list: () => apiGet<NotificationGroupsState>(base),
  users: () => apiGet<GroupMember[]>(`${base}/users`),
  create: (body: { name: string; user_ids: number[] }) => apiPost<NotificationGroup>(base, body),
  update: (id: number, body: { name: string; user_ids: number[] }) => apiPut<NotificationGroup>(`${base}/${id}`, body),
  remove: (id: number) => apiDelete<null>(`${base}/${id}`),
  linkCode: (id: number) => apiPost<NotificationGroup>(`${base}/${id}/link-code`),
  check: (id: number) => apiPost<NotificationGroup>(`${base}/${id}/check`),
  test: (id: number) => apiPost<{ ok: boolean; message: string }>(`${base}/${id}/test`),
  unlink: (id: number) => apiPost<NotificationGroup>(`${base}/${id}/unlink`),
}

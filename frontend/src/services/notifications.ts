import { ref } from 'vue'

import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * Every UserNotification `type` the backend can create — see
 * App\Support\Notifications\NotificationType. Each one needs a matching
 * `notifications.types.*` translation key (see the 5 locale files) since
 * the row itself stores structured `data`, not pre-rendered text — that way
 * it always displays in whichever locale the *reader* is using right now.
 */
export type NotificationTypeValue =
  | 'leave_request_submitted'
  | 'leave_request_approved'
  | 'leave_request_rejected'
  | 'resignation_request_submitted'
  | 'resignation_request_approved'
  | 'resignation_request_rejected'
  | 'make_up_class_request_submitted'
  | 'make_up_class_request_approved_to_study'
  | 'make_up_class_request_approved'
  | 'make_up_class_request_rejected'
  | 'exam_application_submitted'
  | 'exam_application_approved'
  | 'exam_application_rejected'
  | 'approval_request_submitted'
  | 'approval_request_approved'
  | 'approval_request_rejected'
  | 'student_registration_submitted'
  | 'manpower_request_submitted'
  | 'manpower_request_approved'
  | 'manpower_request_rejected'
  | 'job_application_received'
  | 'interview_scheduled'

export interface AppNotification {
  id: number
  type: NotificationTypeValue
  /** Interpolated into `notifications.types.{type}` — e.g. { student_name: 'Sokha' }. */
  data: Record<string, string | number>
  /** A frontend route path to open when clicked, e.g. the Approvals queue. */
  link: string | null
  read_at: string | null
  created_at: string
}

/** The bell's red number — shared, so the Notifications page clearing it updates the bell at once. */
export const unreadNotificationCount = ref(0)

export const notificationsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<AppNotification>> {
    const result = await apiGetWithMeta<AppNotification[]>('/notifications', {
      params: { page: query.page, per_page: query.per_page },
    })
    unreadNotificationCount.value = (result.meta?.unread_count as number) ?? 0

    return {
      data: result.data,
      pagination: result.meta?.pagination as LengthAwarePaginationMeta,
    }
  },

  /**
   * Seeing the list counts as reading it: opening the bell or the
   * Notifications page clears the red number. The rows already on screen
   * keep their unread highlight until the next load, so new ones still
   * stand out while being looked at.
   */
  async markAllSeen(): Promise<void> {
    if (unreadNotificationCount.value === 0) return
    unreadNotificationCount.value = 0
    await apiPost<void>('/notifications/mark-all-read').catch(() => {})
  },
}

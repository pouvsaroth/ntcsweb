import { apiGet, apiPost } from '@/services/http'

export interface WebsiteVisitStats {
  today: number
  yesterday: number
  weekly: number
  monthly: number
  yearly: number
}

/** The public footer's small visitor counter. See WebsiteVisitStats on the backend. */
export const websiteVisitsService = {
  /** Increments today's count and returns the refreshed summary — call at most once per browser per calendar day. */
  record: () => apiPost<WebsiteVisitStats>('/public/visits'),

  /** Read-only — use this once today's visit has already been recorded (see stores/site.ts's pingVisit()). */
  stats: () => apiGet<WebsiteVisitStats>('/public/visits'),
}

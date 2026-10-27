import { apiGet } from '@/services/http'

export interface TermStudyAlert {
  id: number
  student: { id: number; student_code: string | null; name: string } | null
  course: string | null
  class: string | null
  enrolled_at: string | null
  /** Days from enroll date to today. */
  days: number | null
  /** False when listed only because no payment type is recorded (e.g. brought over from the old system). */
  type_recorded: boolean
}

/** Dashboard list: Studying students billed by term who are past the school's day limit (see TermStudyAlertController). */
export const termStudyAlertsService = {
  list: () => apiGet<{ days: number; items: TermStudyAlert[] }>('/term-study-alerts'),
}

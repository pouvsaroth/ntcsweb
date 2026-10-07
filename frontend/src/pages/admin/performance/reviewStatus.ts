import type { ReviewStatus } from '@/services/performance'

/** The badge colour of a performance review's status — the same everywhere a review is listed. */
export const reviewStatusVariant: Record<ReviewStatus, 'warning' | 'primary' | 'success'> = {
  self_assessment: 'warning',
  manager_assessment: 'primary',
  completed: 'success',
}

import type { PayrollRunStatus } from '@/services/payroll'

/** The badge colour of a payroll run's status — the same everywhere a run is listed. */
export const runStatusVariant: Record<PayrollRunStatus, 'neutral' | 'warning' | 'primary' | 'danger' | 'success'> = {
  draft: 'neutral',
  pending: 'warning',
  approved: 'primary',
  rejected: 'danger',
  paid: 'success',
  cancelled: 'neutral',
}

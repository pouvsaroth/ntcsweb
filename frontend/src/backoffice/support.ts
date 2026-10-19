import type { SupportViewState } from './api'

/** Badge colour per support view state — open is the one to notice. */
export const stateVariant: Record<SupportViewState, 'warning' | 'neutral'> = {
  open: 'warning',
  ended: 'neutral',
  timed_out: 'neutral',
}

/** Schools a support view can be opened into (the server checks the same). */
export const supportableStatuses = ['active']

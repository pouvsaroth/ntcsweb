import type { Attendance, CoachingStatus, GoalStatus, ObservationStatus, PlanStatus, TrainingResult, TrainingStatus } from '@/services/professionalDevelopment'

type Variant = 'neutral' | 'primary' | 'success' | 'warning' | 'danger'

/** Badge colours shared by the Professional Development tabs. */
export function trainingStatusVariant(status: TrainingStatus): Variant {
  return ({ planned: 'primary', completed: 'success', cancelled: 'neutral' } as const)[status] ?? 'neutral'
}

export function attendanceVariant(attendance: Attendance): Variant {
  return ({ registered: 'neutral', attended: 'success', absent: 'danger' } as const)[attendance] ?? 'neutral'
}

export function resultVariant(result: TrainingResult | null): Variant {
  return result === 'passed' ? 'success' : result === 'failed' ? 'danger' : 'neutral'
}

/** "dd-mm-yyyy" or "dd-mm-yyyy – dd-mm-yyyy" for a training's days. */
export function trainingDays(start: string, end: string, format: (d: string) => string): string {
  return start === end ? format(start) : `${format(start)} – ${format(end)}`
}

export function observationStatusVariant(status: ObservationStatus): Variant {
  return ({ scheduled: 'primary', completed: 'success', cancelled: 'neutral' } as const)[status] ?? 'neutral'
}

export function coachingStatusVariant(status: CoachingStatus): Variant {
  return ({ active: 'primary', completed: 'success', cancelled: 'neutral' } as const)[status] ?? 'neutral'
}

export function planStatusVariant(status: PlanStatus): Variant {
  return ({ draft: 'neutral', active: 'primary', completed: 'success', cancelled: 'neutral' } as const)[status] ?? 'neutral'
}

export function goalStatusVariant(status: GoalStatus): Variant {
  return ({ not_started: 'neutral', in_progress: 'primary', done: 'success', dropped: 'neutral' } as const)[status] ?? 'neutral'
}

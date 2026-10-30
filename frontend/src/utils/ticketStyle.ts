import type { TicketPriority, TicketStatus } from '@/services/supportTickets'

type Variant = 'neutral' | 'primary' | 'success' | 'warning' | 'danger'

/** Badge colours for a support ticket's status and priority (school app and back office). */
export const ticketStatusVariant = (s: TicketStatus): Variant =>
  ({ open: 'warning', in_progress: 'primary', resolved: 'success', closed: 'neutral' })[s] as Variant

export const ticketPriorityVariant = (p: TicketPriority): Variant =>
  ({ low: 'neutral', normal: 'neutral', high: 'warning', urgent: 'danger' })[p] as Variant

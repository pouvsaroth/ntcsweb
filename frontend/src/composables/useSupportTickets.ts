import { ref } from 'vue'

import { supportTicketService } from '@/services/supportTickets'

/**
 * NewTech's ticket replies the user has not seen yet, shared by the top
 * bar's Support button and the ticket pages (opening a ticket reads it).
 */
const ticketsUnread = ref(0)

async function refreshTicketsUnread(): Promise<void> {
  try {
    ticketsUnread.value = (await supportTicketService.unread()).unread
  } catch {
    /* the dot stays as it was */
  }
}

export function useSupportTickets() {
  return { ticketsUnread, refreshTicketsUnread }
}

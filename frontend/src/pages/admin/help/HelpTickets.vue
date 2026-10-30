<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import TicketFormModal from '@/components/help/TicketFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { supportTicketService, type TicketSummary } from '@/services/supportTickets'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/utils/date'
import { pickText } from '@/utils/helpText'
import { ticketPriorityVariant, ticketStatusVariant } from '@/utils/ticketStyle'

/**
 * Help & Support → Support tickets (docs/backoffice/stage-13-help-support.md, 13b):
 * the user's own tickets to NewTech, newest activity first.
 */
const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()
/** A back office support view is read-only. */
const readOnly = auth.supportView !== null

type Filter = 'active' | 'done' | 'all'
const filter = ref<Filter>('active')
const tickets = ref<TicketSummary[]>([])
const loading = ref(true)
const failed = ref(false)
const formOpen = ref(false)

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    tickets.value = await supportTicketService.list(filter.value === 'all' ? undefined : filter.value)
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}
watch(filter, load)
onMounted(load)

const moduleName = (tk: TicketSummary) => (tk.module ? pickText(locale.value, tk.module_label, tk.module_label_km) || tk.module : t('help.general'))
const open = (tk: TicketSummary) => router.push(`/admin/help/tickets/${tk.id}`)
function onCreated(tk: TicketSummary): void {
  void open(tk)
}

const FILTERS: Filter[] = ['active', 'done', 'all']
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="inline-flex rounded-lg border border-neutral-300 bg-white p-0.5 shadow-sm" role="group">
        <button
          v-for="f in FILTERS"
          :key="f"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="filter === f ? 'bg-primary-500 text-secondary-900' : 'text-neutral-600 hover:bg-neutral-100'"
          :aria-pressed="filter === f"
          @click="filter = f"
        >
          {{ t(`help.tickets.filters.${f}`) }}
        </button>
      </div>
      <BaseButton v-if="!readOnly" class="ml-auto" @click="formOpen = true">{{ t('help.tickets.new') }}</BaseButton>
    </div>

    <BaseAlert v-if="failed" variant="danger">{{ t('help.loadFailed') }}</BaseAlert>
    <div v-else-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <div v-else-if="tickets.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 bg-white px-4 py-10 text-center">
      <p class="text-sm text-neutral-600">{{ t(filter === 'active' ? 'help.tickets.emptyActive' : 'help.tickets.empty') }}</p>
      <p class="mt-1 text-xs text-neutral-500">{{ t('help.tickets.emptyHint') }}</p>
    </div>

    <template v-else>
      <!-- Desktop: a table -->
      <div class="hidden overflow-hidden rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card] sm:block">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead class="bg-neutral-50 text-left text-xs font-semibold tracking-wide text-neutral-500 uppercase">
            <tr>
              <th class="px-4 py-3">{{ t('help.tickets.number') }}</th>
              <th class="px-4 py-3">{{ t('help.tickets.subject') }}</th>
              <th class="px-4 py-3">{{ t('help.tickets.module') }}</th>
              <th class="px-4 py-3">{{ t('help.tickets.priorityLabel') }}</th>
              <th class="px-4 py-3">{{ t('help.tickets.statusLabel') }}</th>
              <th class="px-4 py-3">{{ t('help.tickets.lastActivity') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="tk in tickets" :key="tk.id" class="cursor-pointer hover:bg-neutral-50" @click="open(tk)">
              <td class="px-4 py-3 font-mono text-xs whitespace-nowrap text-neutral-600">{{ tk.number }}</td>
              <td class="px-4 py-3">
                <RouterLink :to="`/admin/help/tickets/${tk.id}`" class="font-medium text-neutral-900 hover:underline" @click.stop>{{ tk.subject }}</RouterLink>
                <span v-if="tk.unread > 0" class="ml-2 rounded-full bg-red-600 px-1.5 text-[10px] font-bold text-white">{{ t('help.tickets.newReply') }}</span>
              </td>
              <td class="px-4 py-3 text-neutral-600">{{ moduleName(tk) }}</td>
              <td class="px-4 py-3"><BaseBadge :variant="ticketPriorityVariant(tk.priority)">{{ t(`help.tickets.priority.${tk.priority}`) }}</BaseBadge></td>
              <td class="px-4 py-3"><BaseBadge :variant="ticketStatusVariant(tk.status)">{{ t(`help.tickets.status.${tk.status}`) }}</BaseBadge></td>
              <td class="px-4 py-3 whitespace-nowrap text-neutral-600">{{ formatDate(tk.last_reply_at ?? tk.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Phone: cards -->
      <ul class="space-y-3 sm:hidden">
        <li v-for="tk in tickets" :key="tk.id">
          <RouterLink :to="`/admin/help/tickets/${tk.id}`" class="block rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <p class="min-w-0 font-medium text-neutral-900">{{ tk.subject }}</p>
              <span v-if="tk.unread > 0" class="shrink-0 rounded-full bg-red-600 px-1.5 text-[10px] font-bold text-white">{{ t('help.tickets.newReply') }}</span>
            </div>
            <p class="mt-1 text-xs text-neutral-500">{{ tk.number }} · {{ moduleName(tk) }} · {{ formatDate(tk.last_reply_at ?? tk.created_at) }}</p>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <BaseBadge :variant="ticketStatusVariant(tk.status)">{{ t(`help.tickets.status.${tk.status}`) }}</BaseBadge>
              <BaseBadge :variant="ticketPriorityVariant(tk.priority)">{{ t(`help.tickets.priority.${tk.priority}`) }}</BaseBadge>
            </div>
          </RouterLink>
        </li>
      </ul>
    </template>

    <TicketFormModal v-model="formOpen" @created="onCreated" />
  </div>
</template>

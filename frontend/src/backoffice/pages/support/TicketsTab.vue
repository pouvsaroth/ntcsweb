<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'
import { formatDate, formatDateTime } from '@/utils/date'
import { ticketPriorityVariant, ticketStatusVariant } from '@/utils/ticketStyle'

import { ticketsApi, type TicketMessageRow, type TicketPriority, type TicketRow, type TicketStatus } from '../../api'
import { useSession } from '../../session'

/**
 * Support → Tickets (docs/backoffice/stage-13-help-support.md, 13b): every
 * school's tickets on the left, one ticket on the right. Answering, status,
 * priority and assignee need platform.support.tickets. No push server: the
 * list refreshes every 30 s, an open ticket every 15 s.
 */
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSession()
const canAnswer = computed(() => session.can('platform.support.tickets'))

const STATUSES: TicketStatus[] = ['open', 'in_progress', 'resolved', 'closed']
const PRIORITIES: TicketPriority[] = ['low', 'normal', 'high', 'urgent']

// ── The list ─────────────────────────────────────────────────────────────────
type Filter = 'waiting' | 'active' | 'resolved' | 'closed' | 'all'
const FILTERS: Filter[] = ['active', 'waiting', 'resolved', 'closed', 'all']
const filter = ref<Filter>('active')
const priority = ref('')
const mine = ref(false)
const search = ref('')
const tickets = ref<TicketRow[]>([])
const listLoading = ref(true)

async function loadList(): Promise<void> {
  const f: Record<string, string> = {}
  if (filter.value !== 'all') f.status = filter.value
  if (priority.value) f.priority = priority.value
  if (mine.value) f.mine = '1'
  try {
    tickets.value = (await ticketsApi.list({ page: 1, per_page: 50, search: search.value.trim() || undefined, filter: f })).data
  } finally {
    listLoading.value = false
  }
}
watch([filter, priority, mine], () => {
  listLoading.value = true
  void loadList()
})
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadList, 300)
})

const priorityFilterOptions = computed(() => [{ value: '', label: t('bo.tickets.anyPriority') }, ...PRIORITIES.map((p) => ({ value: p, label: t(`bo.tickets.priority.${p}`) }))])
const statusOptions = computed(() => STATUSES.map((s) => ({ value: s, label: t(`bo.tickets.status.${s}`) })))
const priorityOptions = computed(() => PRIORITIES.map((p) => ({ value: p, label: t(`bo.tickets.priority.${p}`) })))
const moduleName = (tk: TicketRow) => (tk.module ? (locale.value === 'km' && tk.module_label_km ? tk.module_label_km : tk.module_label || tk.module) : t('bo.tickets.general'))

// ── One ticket ───────────────────────────────────────────────────────────────
const selectedId = computed(() => (route.params.id ? Number(route.params.id) : null))
const ticket = ref<TicketRow | null>(null)
const messages = ref<TicketMessageRow[]>([])
const loadingTicket = ref(false)
const staff = ref<{ id: number; name: string }[]>([])
const staffOptions = computed(() => [{ value: '', label: t('bo.tickets.unassigned') }, ...staff.value.map((s) => ({ value: String(s.id), label: s.name }))])

function apply(data: { ticket: TicketRow; messages: TicketMessageRow[] }): void {
  ticket.value = data.ticket
  messages.value = data.messages
  const row = tickets.value.find((x) => x.id === data.ticket.id)
  if (row) Object.assign(row, data.ticket)
}

async function openTicket(id: number | null, quiet = false): Promise<void> {
  if (!quiet) {
    ticket.value = null
    messages.value = []
  }
  if (id === null) return
  if (!quiet) loadingTicket.value = true
  try {
    apply(await ticketsApi.show(id))
  } catch {
    /* quiet: try again next tick */
  } finally {
    loadingTicket.value = false
  }
}
watch(selectedId, (id) => void openTicket(id))

const select = (id: number) => router.push(`/support/tickets/${id}`)

const updating = ref(false)
const error = ref<string | null>(null)
async function change(body: { status?: TicketStatus; priority?: TicketPriority; assigned_staff_id?: number | null }): Promise<void> {
  if (!ticket.value) return
  updating.value = true
  error.value = null
  try {
    apply(await ticketsApi.update(ticket.value.id, body))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    updating.value = false
  }
}

// ── Reply ────────────────────────────────────────────────────────────────────
const text = ref('')
const files = ref<File[]>([])
const previews = ref<string[]>([])
const sending = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

function addFiles(list: FileList | File[]): void {
  error.value = null
  for (const f of Array.from(list)) {
    if (files.value.length >= 5) break
    if (f.size > 10 * 1024 * 1024) {
      error.value = t('bo.chats.tooBig', { name: f.name })
      continue
    }
    files.value.push(f)
    previews.value.push(f.type.startsWith('image/') ? URL.createObjectURL(f) : '')
  }
}
function removeFile(i: number): void {
  if (previews.value[i]) URL.revokeObjectURL(previews.value[i])
  files.value.splice(i, 1)
  previews.value.splice(i, 1)
}
function onPaste(event: ClipboardEvent): void {
  const pasted = Array.from(event.clipboardData?.files ?? [])
  if (pasted.length) {
    event.preventDefault()
    addFiles(pasted)
  }
}

async function reply(): Promise<void> {
  if (!ticket.value || sending.value || (!text.value.trim() && files.value.length === 0)) return
  sending.value = true
  error.value = null
  try {
    apply(await ticketsApi.reply(ticket.value.id, text.value, files.value))
    text.value = ''
    previews.value.forEach((p) => p && URL.revokeObjectURL(p))
    files.value = []
    previews.value = []
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : t('bo.common.failed')
  } finally {
    sending.value = false
  }
}

const sizeLabel = (bytes: number) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`)

let listTimer: ReturnType<typeof setInterval> | undefined
let ticketTimer: ReturnType<typeof setInterval> | undefined
onMounted(async () => {
  await loadList()
  await openTicket(selectedId.value)
  if (canAnswer.value) {
    try {
      staff.value = await ticketsApi.staff()
    } catch {
      /* the assignee list stays empty */
    }
  }
  listTimer = setInterval(loadList, 30000)
  ticketTimer = setInterval(() => void openTicket(selectedId.value, true), 15000)
})
onBeforeUnmount(() => {
  clearInterval(listTimer)
  clearInterval(ticketTimer)
  previews.value.forEach((p) => p && URL.revokeObjectURL(p))
})
</script>

<template>
  <div class="grid gap-4 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
    <!-- The list (on a phone: hidden while a ticket is open) -->
    <section class="rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card]" :class="{ 'hidden lg:block': selectedId }">
      <div class="space-y-2 border-b border-neutral-100 p-3">
        <div class="flex flex-wrap gap-1">
          <button
            v-for="f in FILTERS"
            :key="f"
            type="button"
            class="rounded-full px-3 py-1 text-xs font-medium"
            :class="filter === f ? 'bg-primary-500 text-secondary-900' : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'"
            @click="filter = f"
          >
            {{ t(`bo.tickets.filters.${f}`) }}
          </button>
        </div>
        <BaseInput v-model="search" :placeholder="t('bo.tickets.search')" />
        <div class="flex items-center gap-3">
          <BaseSelect v-model="priority" :options="priorityFilterOptions" class="min-w-0 flex-1" :aria-label="t('bo.tickets.priorityLabel')" />
          <label class="flex shrink-0 items-center gap-1.5 text-sm text-neutral-700">
            <input v-model="mine" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('bo.tickets.mine') }}
          </label>
        </div>
      </div>
      <div v-if="listLoading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="tickets.length === 0" class="px-4 py-10 text-center text-sm text-neutral-500">{{ t('bo.tickets.empty') }}</p>
      <ul v-else class="max-h-[70vh] divide-y divide-neutral-100 overflow-y-auto">
        <li v-for="tk in tickets" :key="tk.id">
          <button type="button" class="w-full px-4 py-3 text-left hover:bg-neutral-50" :class="{ 'bg-primary-50/60': tk.id === selectedId }" @click="select(tk.id)">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-neutral-900">{{ tk.subject }}</p>
                <p class="truncate text-xs text-neutral-500">{{ tk.number }} · {{ tk.customer?.name ?? '—' }} · {{ tk.user_name }}</p>
              </div>
              <div class="flex shrink-0 flex-col items-end gap-1">
                <span class="text-[11px] text-neutral-400">{{ formatDate(tk.last_reply_at ?? tk.created_at) }}</span>
                <span v-if="tk.unread > 0" class="rounded-full bg-red-600 px-1.5 text-[11px] font-bold text-white">{{ tk.unread }}</span>
              </div>
            </div>
            <div class="mt-1.5 flex flex-wrap gap-1">
              <BaseBadge :variant="ticketStatusVariant(tk.status)">{{ t(`bo.tickets.status.${tk.status}`) }}</BaseBadge>
              <BaseBadge v-if="tk.priority === 'high' || tk.priority === 'urgent'" :variant="ticketPriorityVariant(tk.priority)">{{ t(`bo.tickets.priority.${tk.priority}`) }}</BaseBadge>
              <span v-if="tk.assignee" class="text-[11px] text-neutral-500">→ {{ tk.assignee }}</span>
            </div>
          </button>
        </li>
      </ul>
    </section>

    <!-- One ticket -->
    <section class="flex min-h-[60vh] flex-col rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card]" :class="{ 'hidden lg:flex': !selectedId }">
      <div v-if="!selectedId" class="flex flex-1 items-center justify-center p-8 text-sm text-neutral-500">{{ t('bo.tickets.pick') }}</div>
      <div v-else-if="loadingTicket && !ticket" class="flex flex-1 items-center justify-center"><BaseSpinner /></div>
      <template v-else-if="ticket">
        <header class="space-y-3 border-b border-neutral-100 px-4 py-3">
          <div class="min-w-0">
            <RouterLink to="/support/tickets" class="mr-2 text-sm text-secondary-700 lg:hidden">←</RouterLink>
            <span class="font-mono text-xs text-neutral-500">{{ ticket.number }}</span>
            <h2 class="font-semibold break-words text-neutral-900">{{ ticket.subject }}</h2>
            <p class="text-xs text-neutral-500">
              <RouterLink v-if="ticket.customer" :to="`/schools/${ticket.customer.id}`" class="text-secondary-700 hover:underline">{{ ticket.customer.name }}</RouterLink>
              · {{ ticket.user_name }}<template v-if="ticket.user_email"> ({{ ticket.user_email }})</template>
              · {{ moduleName(ticket) }} · {{ t('bo.tickets.sentOn', { date: formatDateTime(ticket.created_at) }) }}
            </p>
          </div>
          <div v-if="canAnswer" class="grid gap-2 sm:grid-cols-3">
            <BaseSelect :model-value="ticket.status" :options="statusOptions" :label="t('bo.tickets.statusLabel')" :disabled="updating" @update:model-value="change({ status: $event as TicketStatus })" />
            <BaseSelect :model-value="ticket.priority" :options="priorityOptions" :label="t('bo.tickets.priorityLabel')" :disabled="updating" @update:model-value="change({ priority: $event as TicketPriority })" />
            <BaseSelect
              :model-value="ticket.assigned_staff_id ? String(ticket.assigned_staff_id) : ''"
              :options="staffOptions"
              :label="t('bo.tickets.assignee')"
              :disabled="updating"
              @update:model-value="change({ assigned_staff_id: $event ? Number($event) : null })"
            />
          </div>
          <div v-else class="flex flex-wrap gap-1.5">
            <BaseBadge :variant="ticketStatusVariant(ticket.status)">{{ t(`bo.tickets.status.${ticket.status}`) }}</BaseBadge>
            <BaseBadge :variant="ticketPriorityVariant(ticket.priority)">{{ t(`bo.tickets.priority.${ticket.priority}`) }}</BaseBadge>
            <span v-if="ticket.assignee" class="text-xs text-neutral-500">→ {{ ticket.assignee }}</span>
          </div>
        </header>

        <ol class="max-h-[60vh] min-h-0 flex-1 space-y-3 overflow-y-auto bg-neutral-50 px-4 py-3">
          <template v-for="m in messages" :key="m.id">
            <li v-if="m.event" class="text-center text-xs text-neutral-500">
              <span class="rounded-full bg-neutral-200/70 px-3 py-1">
                {{ m.sender === 'staff' ? t('bo.tickets.eventStaff', { name: m.staff, status: t(`bo.tickets.status.${m.event.status}`) }) : t('bo.tickets.eventUser', { status: t(`bo.tickets.status.${m.event.status}`) }) }}
                · {{ formatDateTime(m.created_at) }}
              </span>
            </li>
            <li v-else class="flex" :class="m.sender === 'staff' ? 'justify-end' : 'justify-start'">
              <div class="max-w-[85%]">
                <p class="mb-0.5 text-[11px] text-neutral-500" :class="{ 'text-right': m.sender === 'staff' }">{{ m.sender === 'staff' ? m.staff : ticket.user_name }} · {{ formatDateTime(m.created_at) }}</p>
                <div class="rounded-2xl px-3 py-2 text-sm" :class="m.sender === 'staff' ? 'rounded-br-md bg-secondary-700 text-white' : 'rounded-bl-md border border-neutral-200 bg-white text-neutral-800'">
                  <p v-if="m.body" class="break-words whitespace-pre-line">{{ m.body }}</p>
                  <div v-if="m.attachments.length" class="space-y-1.5" :class="{ 'mt-2': m.body }">
                    <template v-for="(a, i) in m.attachments" :key="i">
                      <a v-if="a.is_image" :href="a.url" target="_blank" rel="noopener" class="block">
                        <img :src="a.url" :alt="a.name" class="max-h-56 rounded-lg border border-black/5 object-contain" />
                      </a>
                      <a v-else :href="a.url" target="_blank" rel="noopener" class="flex items-center gap-2 rounded-lg bg-black/5 px-2 py-1.5 hover:bg-black/10">
                        <span aria-hidden="true">📄</span>
                        <span class="min-w-0 flex-1 truncate underline">{{ a.name }}</span>
                        <span class="shrink-0 text-[11px] opacity-70">{{ sizeLabel(a.size) }}</span>
                      </a>
                    </template>
                  </div>
                </div>
              </div>
            </li>
          </template>
        </ol>

        <footer v-if="canAnswer" class="border-t border-neutral-100 p-3">
          <p v-if="error" class="mb-2 text-xs text-red-600">{{ error }}</p>
          <div v-if="files.length" class="mb-2 flex flex-wrap gap-2">
            <div v-for="(f, i) in files" :key="i" class="relative flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-neutral-50 p-1 pr-6 text-xs">
              <img v-if="previews[i]" :src="previews[i]" alt="" class="h-10 w-10 rounded object-cover" />
              <span v-else class="px-1" aria-hidden="true">📄</span>
              <span class="max-w-[140px] truncate">{{ f.name }}</span>
              <button type="button" class="absolute top-0.5 right-0.5 rounded p-0.5 text-neutral-400 hover:text-red-600" @click="removeFile(i)">✕</button>
            </div>
          </div>
          <div class="flex items-end gap-2">
            <button type="button" class="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100" :title="t('bo.chats.attach')" :aria-label="t('bo.chats.attach')" @click="fileInput?.click()">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
              </svg>
            </button>
            <input
              ref="fileInput"
              type="file"
              multiple
              class="hidden"
              accept="image/jpeg,image/png,image/webp,image/gif,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"
              @change="addFiles(($event.target as HTMLInputElement).files ?? []), (($event.target as HTMLInputElement).value = '')"
            />
            <textarea
              v-model="text"
              rows="3"
              class="max-h-48 flex-1 resize-y rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none"
              :placeholder="t('bo.tickets.placeholder')"
              @paste="onPaste"
            />
            <BaseButton :loading="sending" :disabled="!text.trim() && files.length === 0" @click="reply">{{ t('bo.chats.send') }}</BaseButton>
          </div>
          <p v-if="ticket.status === 'open'" class="mt-1.5 text-[11px] text-neutral-500">{{ t('bo.tickets.firstReplyHint') }}</p>
        </footer>
      </template>
    </section>
  </div>
</template>

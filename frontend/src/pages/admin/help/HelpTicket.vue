<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useSupportTickets } from '@/composables/useSupportTickets'
import { CHAT_ACCEPT, CHAT_MAX_BYTES, CHAT_MAX_FILES } from '@/services/supportChat'
import { supportTicketService, type TicketMessage, type TicketSummary } from '@/services/supportTickets'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate, formatDateTime } from '@/utils/date'
import { pickText } from '@/utils/helpText'
import { ticketPriorityVariant, ticketStatusVariant } from '@/utils/ticketStyle'

/**
 * One support ticket (docs/backoffice/stage-13-help-support.md, 13b): its
 * details, the thread with NewTech (replies, files, status changes), a reply
 * box and Close. Replying to a resolved or closed ticket opens it again.
 * No push server: the thread checks for news every 20 s.
 */
const { t, locale } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const { refreshTicketsUnread } = useSupportTickets()
const readOnly = auth.supportView !== null

const ticket = ref<TicketSummary | null>(null)
const messages = ref<TicketMessage[]>([])
const loading = ref(true)
const missing = ref(false)

const id = computed(() => Number(route.params.id))

async function load(quiet = false): Promise<void> {
  if (!quiet) loading.value = true
  try {
    const data = await supportTicketService.show(id.value)
    ticket.value = data.ticket
    messages.value = data.messages
    void refreshTicketsUnread()
  } catch (e) {
    if (!quiet) missing.value = e instanceof ApiRequestError && e.status === 404
  } finally {
    loading.value = false
  }
}

const moduleName = computed(() => {
  const tk = ticket.value
  if (!tk?.module) return t('help.general')
  return pickText(locale.value, tk.module_label, tk.module_label_km) || tk.module
})
const isDone = computed(() => ticket.value?.status === 'resolved' || ticket.value?.status === 'closed')

// --- Reply ---------------------------------------------------------------------
const text = ref('')
const files = ref<File[]>([])
const previews = ref<string[]>([])
const sending = ref(false)
const error = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

function addFiles(list: FileList | File[]): void {
  error.value = null
  for (const f of Array.from(list)) {
    if (files.value.length >= CHAT_MAX_FILES) {
      error.value = t('supportChat.tooMany', { n: CHAT_MAX_FILES })
      break
    }
    if (f.size > CHAT_MAX_BYTES) {
      error.value = t('supportChat.tooBig', { name: f.name })
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
    addFiles(pasted.map((f, i) => (f.name && f.name !== 'image.png' ? f : new File([f], `screenshot-${Date.now()}-${i}.png`, { type: f.type }))))
  }
}

async function send(): Promise<void> {
  if (sending.value || (!text.value.trim() && files.value.length === 0)) return
  sending.value = true
  error.value = null
  try {
    const data = await supportTicketService.reply(id.value, text.value, files.value)
    ticket.value = data.ticket
    messages.value = data.messages
    text.value = ''
    previews.value.forEach((p) => p && URL.revokeObjectURL(p))
    files.value = []
    previews.value = []
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : t('help.loadFailed')
  } finally {
    sending.value = false
  }
}

async function closeTicket(): Promise<void> {
  if (!(await confirmDialog.confirm({ message: t('help.tickets.closeConfirm') }))) return
  try {
    const data = await supportTicketService.close(id.value)
    ticket.value = data.ticket
    messages.value = data.messages
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('help.loadFailed')
  }
}

const sizeLabel = (bytes: number) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`)

let timer: ReturnType<typeof setInterval> | undefined
onMounted(async () => {
  await load()
  timer = setInterval(() => void load(true), 20000)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  previews.value.forEach((p) => p && URL.revokeObjectURL(p))
})
</script>

<template>
  <div class="max-w-3xl">
    <RouterLink to="/admin/help/tickets" class="mb-4 inline-flex items-center gap-1 text-sm font-medium text-secondary-700 hover:underline">← {{ t('help.tickets.back') }}</RouterLink>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <BaseAlert v-else-if="missing || !ticket" variant="warning">{{ t('help.tickets.missing') }}</BaseAlert>

    <template v-else>
      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="font-mono text-xs text-neutral-500">{{ ticket.number }}</p>
            <h2 class="mt-0.5 text-lg font-semibold break-words text-neutral-900">{{ ticket.subject }}</h2>
          </div>
          <BaseButton v-if="!readOnly && ticket.status !== 'closed'" size="sm" variant="outline" @click="closeTicket">{{ t('help.tickets.close') }}</BaseButton>
        </div>
        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
          <div>
            <dt class="text-xs text-neutral-500">{{ t('help.tickets.statusLabel') }}</dt>
            <dd class="mt-0.5"><BaseBadge :variant="ticketStatusVariant(ticket.status)">{{ t(`help.tickets.status.${ticket.status}`) }}</BaseBadge></dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('help.tickets.priorityLabel') }}</dt>
            <dd class="mt-0.5"><BaseBadge :variant="ticketPriorityVariant(ticket.priority)">{{ t(`help.tickets.priority.${ticket.priority}`) }}</BaseBadge></dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('help.tickets.module') }}</dt>
            <dd class="mt-0.5 text-neutral-800">{{ moduleName }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('help.tickets.sentOn') }}</dt>
            <dd class="mt-0.5 text-neutral-800">{{ formatDate(ticket.created_at) }}</dd>
          </div>
        </dl>
      </section>

      <ol class="mt-4 space-y-3">
        <template v-for="m in messages" :key="m.id">
          <li v-if="m.event" class="text-center text-xs text-neutral-500">
            <span class="rounded-full bg-neutral-100 px-3 py-1">
              {{ t(m.sender === 'staff' ? 'help.tickets.eventStaff' : 'help.tickets.eventYou', { status: t(`help.tickets.status.${m.event.status}`) }) }}
              · {{ formatDateTime(m.created_at) }}
            </span>
          </li>
          <li v-else class="rounded-[--radius-card] border p-4" :class="m.sender === 'staff' ? 'border-secondary-200 bg-secondary-50/50' : 'border-neutral-200 bg-white'">
            <p class="mb-1 text-xs text-neutral-500">
              <span class="font-semibold text-neutral-800">{{ m.sender === 'staff' ? `${m.staff} · NewTech` : t('help.tickets.you') }}</span>
              · {{ formatDateTime(m.created_at) }}
            </p>
            <p v-if="m.body" class="text-sm break-words whitespace-pre-line text-neutral-800">{{ m.body }}</p>
            <div v-if="m.attachments.length" class="mt-2 flex flex-wrap gap-2">
              <template v-for="(a, i) in m.attachments" :key="i">
                <a v-if="a.is_image" :href="a.url" target="_blank" rel="noopener" class="block">
                  <img :src="a.url" :alt="a.name" class="max-h-40 rounded-lg border border-neutral-200 object-contain" />
                </a>
                <a v-else :href="a.url" target="_blank" rel="noopener" class="flex max-w-full items-center gap-2 rounded-lg bg-neutral-100 px-2 py-1.5 text-sm hover:bg-neutral-200">
                  <span aria-hidden="true">📄</span>
                  <span class="min-w-0 truncate underline">{{ a.name }}</span>
                  <span class="shrink-0 text-[11px] text-neutral-500">{{ sizeLabel(a.size) }}</span>
                </a>
              </template>
            </div>
          </li>
        </template>
      </ol>

      <section v-if="!readOnly" class="mt-4 rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <p v-if="isDone" class="mb-2 text-xs text-neutral-500">{{ t('help.tickets.reopenHint') }}</p>
        <p v-if="error" class="mb-2 text-xs text-red-600">{{ error }}</p>
        <div v-if="files.length" class="mb-2 flex flex-wrap gap-2">
          <div v-for="(f, i) in files" :key="i" class="relative flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-neutral-50 p-1 pr-6 text-xs">
            <img v-if="previews[i]" :src="previews[i]" alt="" class="h-10 w-10 rounded object-cover" />
            <span v-else class="px-1" aria-hidden="true">📄</span>
            <span class="max-w-[140px] truncate">{{ f.name }}</span>
            <button type="button" class="absolute top-0.5 right-0.5 rounded p-0.5 text-neutral-400 hover:text-red-600" :aria-label="t('supportChat.remove')" @click="removeFile(i)">✕</button>
          </div>
        </div>
        <label for="ticket-reply" class="sr-only">{{ t('help.tickets.replyPlaceholder') }}</label>
        <textarea
          id="ticket-reply"
          v-model="text"
          rows="3"
          class="block w-full resize-y rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none"
          :placeholder="t('help.tickets.replyPlaceholder')"
          @paste="onPaste"
        />
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
          <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm text-neutral-600 hover:bg-neutral-100" @click="fileInput?.click()">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
            </svg>
            {{ t('help.tickets.attach') }}
          </button>
          <input ref="fileInput" type="file" multiple class="hidden" :accept="CHAT_ACCEPT" @change="addFiles(($event.target as HTMLInputElement).files ?? []), (($event.target as HTMLInputElement).value = '')" />
          <BaseButton :loading="sending" :disabled="!text.trim() && files.length === 0" @click="send">{{ t('help.tickets.sendReply') }}</BaseButton>
        </div>
      </section>
    </template>
  </div>
</template>

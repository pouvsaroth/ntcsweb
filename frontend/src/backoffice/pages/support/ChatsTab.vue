<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import { chatsApi, type ChatMessageRow, type ChatSummary } from '../../api'
import { useSession } from '../../session'

/**
 * Support → Chats (docs/backoffice/stage-10-support-chat.md): customers'
 * questions on the left, one conversation on the right. No push server: the
 * list refreshes every 15 s, an open conversation every 5 s.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSession()
const canAnswer = computed(() => session.can('platform.support.chat'))

// ── The list ─────────────────────────────────────────────────────────────────
type Filter = 'waiting' | 'open' | 'closed' | 'all'
const filter = ref<Filter>('waiting')
const search = ref('')
const chats = ref<ChatSummary[]>([])
const listLoading = ref(true)

async function loadList(): Promise<void> {
  const f: Record<string, string> = {}
  if (filter.value === 'waiting') f.waiting = '1'
  if (filter.value === 'open' || filter.value === 'closed') f.status = filter.value
  try {
    const page = await chatsApi.list({ page: 1, per_page: 50, search: search.value.trim() || undefined, filter: f })
    chats.value = page.data
  } finally {
    listLoading.value = false
  }
}
watch(filter, () => {
  listLoading.value = true
  void loadList()
})
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadList, 300)
})

// ── One conversation ─────────────────────────────────────────────────────────
const selectedId = computed(() => (route.params.id ? Number(route.params.id) : null))
const chat = ref<ChatSummary | null>(null)
const messages = ref<ChatMessageRow[]>([])
const convLoading = ref(false)
const listEl = ref<HTMLElement | null>(null)

async function scrollDown(): Promise<void> {
  await nextTick()
  if (listEl.value) listEl.value.scrollTop = listEl.value.scrollHeight
}

async function openChat(id: number | null): Promise<void> {
  chat.value = null
  messages.value = []
  if (id === null) return
  convLoading.value = true
  try {
    const data = await chatsApi.show(id)
    chat.value = data.chat
    messages.value = data.messages
    // Opening it read the customer's messages.
    const row = chats.value.find((c) => c.id === id)
    if (row) row.unread = 0
  } finally {
    convLoading.value = false
  }
  await scrollDown()
}
watch(selectedId, (id) => void openChat(id))

async function pollChat(): Promise<void> {
  if (!chat.value) return
  const last = messages.value[messages.value.length - 1]?.id ?? 0
  try {
    const data = await chatsApi.show(chat.value.id, last)
    chat.value = data.chat
    if (data.messages.length) {
      messages.value.push(...data.messages.filter((m) => !messages.value.some((x) => x.id === m.id)))
      await scrollDown()
    }
  } catch {
    /* next tick */
  }
}

function select(id: number): void {
  void router.push(`/support/chats/${id}`)
}

// ── Reply ────────────────────────────────────────────────────────────────────
const text = ref('')
const files = ref<File[]>([])
const previews = ref<string[]>([])
const sending = ref(false)
const error = ref<string | null>(null)
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
  if (!chat.value || sending.value || (!text.value.trim() && files.value.length === 0)) return
  sending.value = true
  error.value = null
  try {
    messages.value.push(await chatsApi.reply(chat.value.id, text.value, files.value))
    text.value = ''
    previews.value.forEach((p) => p && URL.revokeObjectURL(p))
    files.value = []
    previews.value = []
    chat.value = { ...chat.value, waiting: false, status: 'open' }
    await scrollDown()
    void loadList()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : t('bo.common.failed')
  } finally {
    sending.value = false
  }
}
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    void reply()
  }
}

async function toggleClosed(): Promise<void> {
  if (!chat.value) return
  chat.value = chat.value.status === 'open' ? await chatsApi.close(chat.value.id) : await chatsApi.reopen(chat.value.id)
  void loadList()
}

const sizeLabel = (bytes: number) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`)

let listTimer: ReturnType<typeof setInterval> | undefined
let chatTimer: ReturnType<typeof setInterval> | undefined
onMounted(async () => {
  await loadList()
  await openChat(selectedId.value)
  listTimer = setInterval(loadList, 15000)
  chatTimer = setInterval(pollChat, 5000)
})
onBeforeUnmount(() => {
  clearInterval(listTimer)
  clearInterval(chatTimer)
  previews.value.forEach((p) => p && URL.revokeObjectURL(p))
})

const FILTERS: Filter[] = ['waiting', 'open', 'closed', 'all']
</script>

<template>
  <div class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
    <!-- The list (on a phone: hidden while a conversation is open) -->
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
            {{ t(`bo.chats.filters.${f}`) }}
          </button>
        </div>
        <BaseInput v-model="search" :placeholder="t('bo.chats.search')" />
      </div>
      <div v-if="listLoading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="chats.length === 0" class="px-4 py-10 text-center text-sm text-neutral-500">{{ t('bo.chats.empty') }}</p>
      <ul v-else class="max-h-[70vh] divide-y divide-neutral-100 overflow-y-auto">
        <li v-for="c in chats" :key="c.id">
          <button type="button" class="w-full px-4 py-3 text-left hover:bg-neutral-50" :class="{ 'bg-primary-50/60': c.id === selectedId }" @click="select(c.id)">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-neutral-900">{{ c.user_name }}</p>
                <p class="truncate text-xs text-neutral-500">{{ c.customer?.name ?? '—' }}</p>
              </div>
              <div class="flex shrink-0 flex-col items-end gap-1">
                <span class="text-[11px] text-neutral-400">{{ c.last_message_at ? formatDateTime(c.last_message_at) : '' }}</span>
                <span v-if="c.unread > 0" class="rounded-full bg-red-600 px-1.5 text-[11px] font-bold text-white">{{ c.unread }}</span>
                <BaseBadge v-else-if="c.waiting" variant="warning">{{ t('bo.chats.waiting') }}</BaseBadge>
                <BaseBadge v-else-if="c.status === 'closed'">{{ t('bo.chats.closed') }}</BaseBadge>
              </div>
            </div>
            <p v-if="c.last_message" class="mt-1 truncate text-xs text-neutral-600">
              <span v-if="c.last_message.sender === 'staff'" class="text-neutral-400">{{ t('bo.chats.you') }}: </span>{{ c.last_message.text }}
            </p>
          </button>
        </li>
      </ul>
    </section>

    <!-- The conversation -->
    <section class="flex min-h-[60vh] flex-col rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card]" :class="{ 'hidden lg:flex': !selectedId }">
      <div v-if="!selectedId" class="flex flex-1 items-center justify-center p-8 text-sm text-neutral-500">{{ t('bo.chats.pick') }}</div>
      <div v-else-if="convLoading && !chat" class="flex flex-1 items-center justify-center"><BaseSpinner /></div>
      <template v-else-if="chat">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 px-4 py-3">
          <div class="min-w-0">
            <RouterLink to="/support/chats" class="mr-2 text-sm text-secondary-700 lg:hidden">←</RouterLink>
            <span class="font-semibold text-neutral-900">{{ chat.user_name }}</span>
            <span v-if="chat.user_email" class="ml-1 text-xs text-neutral-500">{{ chat.user_email }}</span>
            <p class="text-xs text-neutral-500">
              <RouterLink v-if="chat.customer" :to="`/schools/${chat.customer.id}`" class="text-secondary-700 hover:underline">{{ chat.customer.name }}</RouterLink>
              <template v-if="chat.status === 'closed'"> · {{ t('bo.chats.closed') }}</template>
            </p>
          </div>
          <BaseButton v-if="canAnswer" size="sm" variant="outline" @click="toggleClosed">
            {{ chat.status === 'open' ? t('bo.chats.close') : t('bo.chats.reopen') }}
          </BaseButton>
        </header>

        <div ref="listEl" class="max-h-[60vh] min-h-0 flex-1 space-y-3 overflow-y-auto bg-neutral-50 px-4 py-3">
          <div v-for="m in messages" :key="m.id" class="flex" :class="m.sender === 'staff' ? 'justify-end' : 'justify-start'">
            <div class="max-w-[80%]">
              <p v-if="m.sender === 'staff'" class="mb-0.5 text-right text-[11px] text-neutral-500">{{ m.staff }}</p>
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
              <p class="mt-0.5 text-[10px] text-neutral-400" :class="{ 'text-right': m.sender === 'staff' }">{{ formatDateTime(m.created_at) }}</p>
            </div>
          </div>
        </div>

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
              rows="2"
              class="max-h-40 flex-1 resize-y rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none"
              :placeholder="t('bo.chats.placeholder')"
              @keydown="onKeydown"
              @paste="onPaste"
            />
            <BaseButton :loading="sending" :disabled="!text.trim() && files.length === 0" @click="reply">{{ t('bo.chats.send') }}</BaseButton>
          </div>
        </footer>
      </template>
    </section>
  </div>
</template>

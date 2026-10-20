<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { CHAT_ACCEPT, CHAT_MAX_BYTES, CHAT_MAX_FILES, supportChatService, type ChatMessage } from '@/services/supportChat'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

/**
 * The support chat button in the top bar (docs/backoffice/stage-10-support-chat.md):
 * a red dot when NewTech replied; a panel with the conversation, text,
 * images (paste a screenshot with Ctrl+V) and files. Staff users only —
 * not students, not platform accounts. No push server: the open panel asks
 * for new messages every 5 s, the dot every 30 s.
 */
const { t } = useI18n()
const auth = useAuthStore()

const allowed = computed(() => auth.isAuthenticated && !auth.isSuperAdmin && auth.tenantName !== null && !auth.hasRole('student'))
/** Inside a back office support view everything is read-only. */
const readOnly = computed(() => auth.supportView !== null)

const open = ref(false)
const unread = ref(0)
const messages = ref<ChatMessage[]>([])
const loading = ref(false)
const text = ref('')
const files = ref<File[]>([])
const previews = ref<string[]>([])
const sending = ref(false)
const error = ref<string | null>(null)
const list = ref<HTMLElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

let fastTimer: ReturnType<typeof setInterval> | undefined
let slowTimer: ReturnType<typeof setInterval> | undefined

const lastId = () => messages.value[messages.value.length - 1]?.id ?? 0

async function scrollDown(): Promise<void> {
  await nextTick()
  if (list.value) list.value.scrollTop = list.value.scrollHeight
}

async function refreshUnread(): Promise<void> {
  if (!allowed.value || open.value) return
  try {
    unread.value = (await supportChatService.unread()).unread
  } catch {
    /* the dot just stays as it was */
  }
}

/** New messages since the last one shown. */
async function poll(): Promise<void> {
  try {
    const state = await supportChatService.show(lastId())
    if (state.messages.length) {
      messages.value.push(...state.messages.filter((m) => !messages.value.some((x) => x.id === m.id)))
      await scrollDown()
      if (!readOnly.value) await supportChatService.read()
    }
  } catch {
    /* try again next tick */
  }
}

async function openPanel(): Promise<void> {
  open.value = true
  loading.value = true
  error.value = null
  try {
    messages.value = (await supportChatService.show()).messages
    unread.value = 0
    if (!readOnly.value) await supportChatService.read()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('supportChat.failed')
  } finally {
    loading.value = false
  }
  await scrollDown()
  fastTimer = setInterval(poll, 5000)
}

function closePanel(): void {
  open.value = false
  clearInterval(fastTimer)
}

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

/** A screenshot pasted with Ctrl+V becomes an attachment. */
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
    const message = await supportChatService.send(text.value, files.value)
    messages.value.push(message)
    text.value = ''
    previews.value.forEach((p) => p && URL.revokeObjectURL(p))
    files.value = []
    previews.value = []
    await scrollDown()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : t('supportChat.failed')
  } finally {
    sending.value = false
  }
}

function onKeydown(event: KeyboardEvent): void {
  // Enter sends; Shift+Enter is a new line.
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    void send()
  }
}

const sizeLabel = (bytes: number) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`)

onMounted(() => {
  void refreshUnread()
  slowTimer = setInterval(refreshUnread, 30000)
})
onBeforeUnmount(() => {
  clearInterval(fastTimer)
  clearInterval(slowTimer)
  previews.value.forEach((p) => p && URL.revokeObjectURL(p))
})
watch(allowed, (on) => on && void refreshUnread())
</script>

<template>
  <div v-if="allowed">
    <button
      type="button"
      class="relative rounded-lg p-2 text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
      :aria-label="t('supportChat.title')"
      :title="t('supportChat.title')"
      @click="open ? closePanel() : openPanel()"
    >
      <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"
        />
      </svg>
      <span v-if="unread > 0" class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">
        {{ unread > 9 ? '9+' : unread }}
      </span>
    </button>

    <Teleport to="body">
      <div v-if="open" class="fixed inset-0 z-40 bg-neutral-900/20 sm:bg-transparent" @click="closePanel" />
      <Transition enter-active-class="transition ease-out duration-150" enter-from-class="translate-x-4 opacity-0" enter-to-class="translate-x-0 opacity-100">
        <section
          v-if="open"
          class="fixed inset-x-0 bottom-0 z-50 flex h-[85vh] flex-col rounded-t-2xl border border-neutral-200 bg-white shadow-2xl sm:inset-x-auto sm:top-16 sm:right-4 sm:bottom-4 sm:h-auto sm:w-[400px] sm:rounded-2xl"
          role="dialog"
          :aria-label="t('supportChat.title')"
        >
          <header class="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
            <div>
              <h2 class="font-semibold text-neutral-900">{{ t('supportChat.title') }}</h2>
              <p class="text-xs text-neutral-500">{{ t('supportChat.subtitle') }}</p>
            </div>
            <button type="button" class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600" :aria-label="t('common.close')" @click="closePanel">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </header>

          <div ref="list" class="min-h-0 flex-1 space-y-3 overflow-y-auto bg-neutral-50 px-4 py-3">
            <p v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
            <div v-else-if="messages.length === 0" class="py-8 text-center text-sm text-neutral-500">
              <p class="text-3xl" aria-hidden="true">👋</p>
              <p class="mt-2">{{ t('supportChat.empty') }}</p>
            </div>
            <div v-for="m in messages" :key="m.id" class="flex" :class="m.sender === 'user' ? 'justify-end' : 'justify-start'">
              <div class="max-w-[85%]">
                <p v-if="m.sender === 'staff'" class="mb-0.5 text-[11px] text-neutral-500">{{ m.staff }} · NewTech</p>
                <div class="rounded-2xl px-3 py-2 text-sm" :class="m.sender === 'user' ? 'rounded-br-md bg-primary-500 text-secondary-900' : 'rounded-bl-md border border-neutral-200 bg-white text-neutral-800'">
                  <p v-if="m.body" class="break-words whitespace-pre-line">{{ m.body }}</p>
                  <div v-if="m.attachments.length" class="space-y-1.5" :class="{ 'mt-2': m.body }">
                    <template v-for="(a, i) in m.attachments" :key="i">
                      <a v-if="a.is_image" :href="a.url" target="_blank" rel="noopener" class="block">
                        <img :src="a.url" :alt="a.name" class="max-h-48 rounded-lg border border-black/5 object-contain" />
                      </a>
                      <a v-else :href="a.url" target="_blank" rel="noopener" class="flex items-center gap-2 rounded-lg bg-black/5 px-2 py-1.5 hover:bg-black/10">
                        <span aria-hidden="true">📄</span>
                        <span class="min-w-0 flex-1 truncate underline">{{ a.name }}</span>
                        <span class="shrink-0 text-[11px] opacity-70">{{ sizeLabel(a.size) }}</span>
                      </a>
                    </template>
                  </div>
                </div>
                <p class="mt-0.5 text-[10px] text-neutral-400" :class="{ 'text-right': m.sender === 'user' }">{{ formatDateTime(m.created_at) }}</p>
              </div>
            </div>
          </div>

          <footer v-if="!readOnly" class="border-t border-neutral-100 p-3">
            <p v-if="error" class="mb-2 text-xs text-red-600">{{ error }}</p>
            <div v-if="files.length" class="mb-2 flex flex-wrap gap-2">
              <div v-for="(f, i) in files" :key="i" class="relative flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-neutral-50 p-1 pr-6 text-xs">
                <img v-if="previews[i]" :src="previews[i]" alt="" class="h-10 w-10 rounded object-cover" />
                <span v-else class="px-1" aria-hidden="true">📄</span>
                <span class="max-w-[120px] truncate">{{ f.name }}</span>
                <button type="button" class="absolute top-0.5 right-0.5 rounded p-0.5 text-neutral-400 hover:text-red-600" :aria-label="t('supportChat.remove')" @click="removeFile(i)">✕</button>
              </div>
            </div>
            <div class="flex items-end gap-2">
              <button
                type="button"
                class="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-800"
                :title="t('supportChat.attach')"
                :aria-label="t('supportChat.attach')"
                @click="fileInput?.click()"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                </svg>
              </button>
              <input ref="fileInput" type="file" multiple class="hidden" :accept="CHAT_ACCEPT" @change="addFiles(($event.target as HTMLInputElement).files ?? []), (($event.target as HTMLInputElement).value = '')" />
              <textarea
                v-model="text"
                rows="1"
                class="max-h-32 min-h-[40px] flex-1 resize-none rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none"
                :placeholder="t('supportChat.placeholder')"
                @keydown="onKeydown"
                @paste="onPaste"
              />
              <button
                type="button"
                class="rounded-lg bg-primary-500 px-3 py-2 text-sm font-semibold text-secondary-900 hover:bg-primary-400 disabled:opacity-50"
                :disabled="sending || (!text.trim() && files.length === 0)"
                @click="send"
              >
                {{ sending ? '…' : t('supportChat.send') }}
              </button>
            </div>
            <p class="mt-1.5 text-[11px] text-neutral-400">{{ t('supportChat.hint') }}</p>
          </footer>
          <p v-else class="border-t border-neutral-100 p-3 text-center text-xs text-neutral-500">{{ t('supportChat.readOnly') }}</p>
        </section>
      </Transition>
    </Teleport>
  </div>
</template>

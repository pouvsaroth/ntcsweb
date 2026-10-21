<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { notificationGroupsService, type GroupMember, type NotificationGroup } from '@/services/notificationGroups'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * Settings → Telegram notifications (docs/backoffice/stage-11-telegram-notifications.md):
 * groups of users; each group linked to a Telegram group gets every bell
 * notification of its members there.
 */
const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

const groups = ref<NotificationGroup[]>([])
const botReady = ref(true)
const botName = ref('newtechkh_bot')
const loading = ref(true)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  try {
    const state = await notificationGroupsService.list()
    groups.value = state.groups
    botReady.value = state.bot_ready
    botName.value = state.bot_username || 'newtechkh_bot'
    error.value = null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('telegramGroups.failed')
  } finally {
    loading.value = false
  }
}
onMounted(load)

const replace = (g: NotificationGroup) => (groups.value = groups.value.map((x) => (x.id === g.id ? g : x)))

// ── Add / edit a group: name and members ─────────────────────────────────────
const users = ref<GroupMember[]>([])
const editing = ref<NotificationGroup | null>(null)
const formOpen = ref(false)
const name = ref('')
const picked = ref<number[]>([])
const userSearch = ref('')
const saving = ref(false)
const formError = ref<ApiRequestError | null>(null)

const shownUsers = computed(() => {
  const q = userSearch.value.trim().toLowerCase()
  return q ? users.value.filter((u) => u.name.toLowerCase().includes(q) || (u.email ?? '').toLowerCase().includes(q)) : users.value
})

async function openForm(g: NotificationGroup | null): Promise<void> {
  editing.value = g
  name.value = g?.name ?? ''
  picked.value = g ? g.users.map((u) => u.id) : []
  userSearch.value = ''
  formError.value = null
  formOpen.value = true
  if (users.value.length === 0) users.value = await notificationGroupsService.users()
}

function toggleUser(id: number): void {
  picked.value = picked.value.includes(id) ? picked.value.filter((x) => x !== id) : [...picked.value, id]
}

async function save(): Promise<void> {
  saving.value = true
  formError.value = null
  try {
    const body = { name: name.value.trim(), user_ids: picked.value }
    if (editing.value) replace(await notificationGroupsService.update(editing.value.id, body))
    else groups.value = [...groups.value, await notificationGroupsService.create(body)]
    formOpen.value = false
  } catch (e) {
    formError.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('telegramGroups.failed'), 0)
  } finally {
    saving.value = false
  }
}

async function remove(g: NotificationGroup): Promise<void> {
  if (!(await confirmDialog.confirm(t('telegramGroups.removeConfirm', { name: g.name })))) return
  await notificationGroupsService.remove(g.id)
  groups.value = groups.value.filter((x) => x.id !== g.id)
}

// ── Link to Telegram ─────────────────────────────────────────────────────────
const busy = ref<number | null>(null)
const notice = ref<Record<number, { ok: boolean; text: string }>>({})
let checkTimer: ReturnType<typeof setInterval> | undefined

async function startLink(g: NotificationGroup): Promise<void> {
  busy.value = g.id
  try {
    replace(await notificationGroupsService.linkCode(g.id))
    // Look for the "/link" message every 10 seconds while a code is waiting.
    clearInterval(checkTimer)
    checkTimer = setInterval(() => void checkWaiting(), 10000)
  } finally {
    busy.value = null
  }
}

async function checkWaiting(): Promise<void> {
  const waiting = groups.value.filter((g) => g.link_code && !g.linked)
  if (waiting.length === 0) {
    clearInterval(checkTimer)
    return
  }
  for (const g of waiting) {
    try {
      const fresh = await notificationGroupsService.check(g.id)
      replace(fresh)
      if (fresh.linked) notice.value[g.id] = { ok: true, text: t('telegramGroups.linkedNow') }
    } catch {
      /* next round */
    }
  }
}

async function checkNow(g: NotificationGroup): Promise<void> {
  busy.value = g.id
  try {
    const fresh = await notificationGroupsService.check(g.id)
    replace(fresh)
    notice.value[g.id] = fresh.linked ? { ok: true, text: t('telegramGroups.linkedNow') } : { ok: false, text: t('telegramGroups.notYet') }
  } finally {
    busy.value = null
  }
}

async function sendTest(g: NotificationGroup): Promise<void> {
  busy.value = g.id
  try {
    const r = await notificationGroupsService.test(g.id)
    notice.value[g.id] = { ok: r.ok, text: r.ok ? t('telegramGroups.testSent') : r.message }
  } catch (e) {
    notice.value[g.id] = { ok: false, text: e instanceof ApiRequestError ? e.message : t('telegramGroups.failed') }
  } finally {
    busy.value = null
  }
}

async function unlink(g: NotificationGroup): Promise<void> {
  if (!(await confirmDialog.confirm(t('telegramGroups.unlinkConfirm', { name: g.name })))) return
  replace(await notificationGroupsService.unlink(g.id))
}

const copied = ref<number | null>(null)
async function copyCode(g: NotificationGroup): Promise<void> {
  await navigator.clipboard?.writeText(`/link ${g.link_code}`).catch(() => {})
  copied.value = g.id
  setTimeout(() => (copied.value = null), 2000)
}

onBeforeUnmount(() => clearInterval(checkTimer))
</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('telegramGroups.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-600">{{ t('telegramGroups.intro') }}</p>
      </div>
      <BaseButton @click="openForm(null)">{{ t('telegramGroups.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="!botReady" variant="warning" class="mb-4">{{ t('telegramGroups.botNotReady') }}</BaseAlert>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <p v-if="loading" class="py-10 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <div v-else-if="groups.length === 0" class="rounded-xl border border-dashed border-neutral-300 p-8 text-center">
      <p class="text-3xl" aria-hidden="true">📣</p>
      <p class="mt-2 text-sm text-neutral-600">{{ t('telegramGroups.empty') }}</p>
    </div>

    <div v-else class="space-y-4">
      <section v-for="g in groups" :key="g.id" class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div class="min-w-0">
            <h2 class="font-semibold text-neutral-900">{{ g.name }}</h2>
            <p v-if="g.linked" class="mt-0.5 text-sm text-green-700">✓ {{ t('telegramGroups.linkedTo', { title: g.telegram_title || 'Telegram' }) }}</p>
            <p v-else class="mt-0.5 text-sm text-amber-700">{{ t('telegramGroups.notLinked') }}</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <BaseButton size="sm" variant="outline" @click="openForm(g)">{{ t('telegramGroups.edit') }}</BaseButton>
            <BaseButton size="sm" variant="ghost" class="!text-red-600" @click="remove(g)">{{ t('telegramGroups.remove') }}</BaseButton>
          </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-1.5">
          <span v-for="u in g.users" :key="u.id" class="rounded-full bg-neutral-100 px-2.5 py-0.5 text-xs text-neutral-700">{{ u.name }}</span>
          <span v-if="g.users.length === 0" class="text-xs text-neutral-400">{{ t('telegramGroups.noMembers') }}</span>
        </div>

        <!-- Linking -->
        <div v-if="!g.linked" class="mt-4 rounded-lg bg-neutral-50 p-4 text-sm">
          <template v-if="g.link_code">
            <ol class="list-decimal space-y-1.5 pl-5 text-neutral-700">
              <li>{{ t('telegramGroups.step1', { bot: `@${botName}` }) }}</li>
              <li>
                {{ t('telegramGroups.step2') }}
                <span class="ml-1 inline-flex items-center gap-2 rounded-md border border-neutral-300 bg-white px-2 py-0.5 font-mono font-semibold text-neutral-900">
                  /link {{ g.link_code }}
                  <button type="button" class="text-xs font-normal text-secondary-700 hover:underline" @click="copyCode(g)">
                    {{ copied === g.id ? t('telegramGroups.copied') : t('telegramGroups.copy') }}
                  </button>
                </span>
              </li>
              <li>{{ t('telegramGroups.step3') }}</li>
            </ol>
            <div class="mt-3 flex flex-wrap items-center gap-2">
              <BaseButton size="sm" :loading="busy === g.id" @click="checkNow(g)">{{ t('telegramGroups.checkNow') }}</BaseButton>
              <span class="text-xs text-neutral-500">{{ t('telegramGroups.codeValid') }}</span>
            </div>
          </template>
          <BaseButton v-else size="sm" :loading="busy === g.id" :disabled="!botReady" @click="startLink(g)">{{ t('telegramGroups.link') }}</BaseButton>
        </div>
        <div v-else class="mt-4 flex flex-wrap gap-2">
          <BaseButton size="sm" variant="outline" :loading="busy === g.id" @click="sendTest(g)">{{ t('telegramGroups.test') }}</BaseButton>
          <BaseButton size="sm" variant="ghost" @click="unlink(g)">{{ t('telegramGroups.unlink') }}</BaseButton>
        </div>
        <p v-if="notice[g.id]" class="mt-2 text-sm" :class="notice[g.id].ok ? 'text-green-700' : 'text-red-600'">{{ notice[g.id].text }}</p>
      </section>
    </div>

    <BaseModal v-model="formOpen" :title="editing ? t('telegramGroups.edit') : t('telegramGroups.add')">
      <form id="tg-group" class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="formError && !formError.errors" variant="danger">{{ formError.message }}</BaseAlert>
        <BaseInput v-model="name" :label="t('telegramGroups.name')" :placeholder="t('telegramGroups.nameExample')" :error="formError?.fieldError('name')" required />
        <div>
          <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('telegramGroups.members') }} ({{ picked.length }})</p>
          <BaseInput v-model="userSearch" :placeholder="t('telegramGroups.searchUsers')" />
          <div class="mt-2 max-h-64 space-y-1 overflow-y-auto rounded-lg border border-neutral-200 p-2">
            <label v-for="u in shownUsers" :key="u.id" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-neutral-50">
              <input type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :checked="picked.includes(u.id)" @change="toggleUser(u.id)" />
              <span class="min-w-0 flex-1 truncate">{{ u.name }}</span>
              <span class="truncate text-xs text-neutral-400">{{ u.email }}</span>
            </label>
            <p v-if="shownUsers.length === 0" class="px-2 py-3 text-center text-xs text-neutral-400">{{ t('telegramGroups.noUsers') }}</p>
          </div>
          <p v-if="formError?.fieldError('user_ids')" class="mt-1 text-xs text-red-600">{{ formError.fieldError('user_ids') }}</p>
        </div>
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="formOpen = false">{{ t('common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="tg-group" :loading="saving">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

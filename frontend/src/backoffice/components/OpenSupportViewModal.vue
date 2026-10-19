<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'

import { supportApi, type School, type SupportUser, type SupportView } from '../api'

/**
 * Open a read-only support view: pick whose eyes (the owner preselected),
 * type why, and the console opens in a new tab with a one-time ticket.
 */
const props = defineProps<{ modelValue: boolean; school: School }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; opened: [view: SupportView] }>()

const { t } = useI18n()

const users = ref<SupportUser[]>([])
const userId = ref('')
const reason = ref('')
const busy = ref(false)
const error = ref<ApiRequestError | null>(null)
/** Shown when the browser blocked the new tab; the ticket still works once, for 60 seconds. */
const blockedUrl = ref<string | null>(null)

const userOptions = computed(() =>
  users.value.map((u) => ({
    value: String(u.id),
    label: [u.name, u.email ?? u.phone].filter(Boolean).join(' · ') + (u.is_owner ? ` (${t('bo.support.owner')})` : ''),
  })),
)

/** Up to 50 users come back; the search narrows them on the server. */
const search = ref('')
let searchTimer: ReturnType<typeof setTimeout> | undefined

async function loadUsers(): Promise<void> {
  try {
    users.value = await supportApi.users(props.school.id, search.value.trim() || undefined)
    if (!users.value.some((u) => String(u.id) === userId.value)) userId.value = users.value[0] ? String(users.value[0].id) : ''
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : null
  }
}

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void loadUsers(), 300)
})

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    reason.value = ''
    search.value = ''
    userId.value = ''
    error.value = null
    blockedUrl.value = null
    await loadUsers()
  },
  { immediate: true },
)

async function submit(): Promise<void> {
  // Open the tab now, while this is still the click: a tab opened after the
  // request returns is often blocked as a pop-up.
  const tab = window.open('', '_blank')
  busy.value = true
  error.value = null
  try {
    const view = await supportApi.open(props.school.id, { user_id: Number(userId.value), reason: reason.value })
    emit('opened', view)
    if (tab) {
      tab.opener = null
      tab.location.href = view.url
      emit('update:modelValue', false)
    } else {
      blockedUrl.value = view.url
    }
  } catch (e) {
    tab?.close()
    error.value = e instanceof ApiRequestError ? e : null
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('bo.support.openTitle')" size="md" @update:model-value="emit('update:modelValue', $event)">
    <div v-if="blockedUrl" class="space-y-3">
      <BaseAlert variant="warning">{{ t('bo.support.tabBlocked') }}</BaseAlert>
      <a :href="blockedUrl" target="_blank" rel="noopener" class="font-medium text-secondary-700 hover:underline" @click="emit('update:modelValue', false)">
        {{ t('bo.support.openConsole') }}
      </a>
    </div>

    <form v-else id="bo-support-open" class="space-y-4" @submit.prevent="submit">
      <p class="text-sm text-neutral-600">{{ t('bo.support.openHint') }}</p>
      <BaseAlert v-if="error && !error.fieldError('reason') && !error.fieldError('user_id')" variant="danger">
        {{ error.fieldError('school') ?? error.message }}
      </BaseAlert>
      <BaseInput v-model="search" :label="t('bo.support.findUser')" :placeholder="t('bo.support.findUserPlaceholder')" />
      <BaseSelect
        v-model="userId"
        :label="t('bo.support.viewAs')"
        :options="userOptions"
        :placeholder="users.length ? undefined : t('bo.support.noUsers')"
        :error="error?.fieldError('user_id') ?? undefined"
        required
      />
      <BaseInput
        v-model="reason"
        :label="t('bo.support.reason')"
        :placeholder="t('bo.support.reasonPlaceholder')"
        :error="error?.fieldError('reason') ?? undefined"
        required
      />
      <p class="text-xs text-neutral-500">{{ t('bo.support.readOnlyNote') }}</p>
    </form>

    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ blockedUrl ? t('bo.common.close') : t('bo.common.cancel') }}</BaseButton>
      <BaseButton v-if="!blockedUrl" type="submit" form="bo-support-open" :loading="busy" :disabled="!userId">{{ t('bo.support.open') }}</BaseButton>
    </template>
  </BaseModal>
</template>

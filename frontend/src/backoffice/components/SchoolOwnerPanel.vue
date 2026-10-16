<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import { schoolsApi, type School } from '../api'

/** The school's owner: invited or accepted, resend the invite, or invite someone else. Never a password. */
const props = defineProps<{ school: School; canWrite: boolean }>()
const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()

const message = ref<string | null>(null)
const error = ref<string | null>(null)
const busy = ref(false)

async function resend(): Promise<void> {
  busy.value = true
  message.value = null
  error.value = null
  try {
    await schoolsApi.resendInvite(props.school.id)
    message.value = t('bo.schools.resent')
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.fieldError('owner') ?? e.message) : t('bo.common.failed')
  } finally {
    busy.value = false
  }
}

// No email? A fresh link to copy and send another way (Telegram, SMS…).
const link = ref<{ url: string; days: number } | null>(null)
const linkCopied = ref(false)

async function makeLink(): Promise<void> {
  busy.value = true
  message.value = null
  error.value = null
  linkCopied.value = false
  try {
    const result = await schoolsApi.inviteLink(props.school.id)
    link.value = { url: result.url, days: result.expires_in_days }
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.fieldError('owner') ?? e.message) : t('bo.common.failed')
  } finally {
    busy.value = false
  }
}

async function copyLink(): Promise<void> {
  if (!link.value) return
  try {
    await navigator.clipboard.writeText(link.value.url)
    linkCopied.value = true
  } catch {
    // Clipboard blocked: the link is selected in the box to copy by hand.
  }
}

const changeOpen = ref(false)
const name = ref('')
const email = ref('')
const changeError = ref<ApiRequestError | null>(null)

function openChange(): void {
  name.value = ''
  email.value = ''
  changeError.value = null
  changeOpen.value = true
}

async function change(): Promise<void> {
  busy.value = true
  changeError.value = null
  try {
    await schoolsApi.changeOwner(props.school.id, name.value, email.value)
    changeOpen.value = false
    emit('changed')
  } catch (e) {
    changeError.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
    <h2 class="mb-3 font-semibold text-neutral-900">{{ t('bo.schools.owner') }}</h2>
    <BaseAlert v-if="message" variant="success" class="mb-3">{{ message }}</BaseAlert>
    <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>

    <template v-if="school.owner">
      <p class="font-medium text-neutral-900">{{ school.owner.name }}</p>
      <p class="text-sm break-all text-neutral-600">{{ school.owner.email }}</p>
      <div class="mt-2 flex flex-wrap items-center gap-2">
        <BaseBadge :variant="school.owner.accepted ? 'success' : 'warning'">
          {{ school.owner.accepted ? t('bo.schools.ownerAccepted') : t('bo.schools.ownerInvited') }}
        </BaseBadge>
        <span v-if="!school.owner.accepted && school.invite_sent_at" class="text-xs text-neutral-500">
          {{ t('bo.schools.inviteSent', { when: formatDateTime(school.invite_sent_at) }) }}
        </span>
      </div>
    </template>
    <p v-else class="text-sm text-neutral-500">{{ t('bo.schools.noOwner') }}</p>

    <div v-if="canWrite && school.status === 'active'" class="mt-4 flex flex-wrap gap-2">
      <BaseButton v-if="school.owner && !school.owner.accepted" size="sm" :loading="busy" @click="makeLink">
        {{ t('bo.schools.copyLink') }}
      </BaseButton>
      <BaseButton v-if="school.owner && !school.owner.accepted" size="sm" variant="outline" :loading="busy" @click="resend">
        {{ t('bo.schools.resend') }}
      </BaseButton>
      <BaseButton size="sm" variant="ghost" @click="openChange">{{ t('bo.schools.changeOwner') }}</BaseButton>
    </div>

    <BaseModal :model-value="link !== null" :title="t('bo.schools.linkTitle')" @update:model-value="link = null">
      <template v-if="link">
        <p class="text-sm text-neutral-600">{{ t('bo.schools.linkHint', { name: school.owner?.name ?? '', days: link.days }) }}</p>
        <textarea
          readonly
          rows="4"
          class="mt-3 w-full rounded-lg border-neutral-300 bg-neutral-50 font-mono text-xs break-all text-neutral-800"
          :value="link.url"
          @focus="($event.target as HTMLTextAreaElement).select()"
        />
        <p class="mt-2 text-xs text-amber-700">{{ t('bo.schools.linkReplaced') }}</p>
      </template>
      <template #footer>
        <BaseButton variant="ghost" @click="link = null">{{ t('bo.common.close') }}</BaseButton>
        <BaseButton @click="copyLink">{{ linkCopied ? t('bo.schools.copied') : t('bo.schools.copy') }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="changeOpen" :title="t('bo.schools.changeOwner')" size="sm">
      <form id="bo-owner-change" class="space-y-3" @submit.prevent="change">
        <p class="text-sm text-neutral-600">{{ t('bo.schools.changeOwnerHint') }}</p>
        <BaseAlert v-if="changeError && !changeError.errors" variant="danger">{{ changeError.message }}</BaseAlert>
        <BaseInput v-model="name" :label="t('bo.schools.ownerName')" :error="changeError?.fieldError('name')" required />
        <BaseInput v-model="email" :label="t('bo.schools.ownerEmail')" type="email" :error="changeError?.fieldError('email')" required />
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="changeOpen = false">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="bo-owner-change" :loading="busy">{{ t('bo.schools.changeOwner') }}</BaseButton>
      </template>
    </BaseModal>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

import { schoolsApi, type School, type SchoolDomain } from '../api'

/**
 * Custom domains. A new one waits for its DNS TXT record (Check now), and only
 * then starts pointing at the school. `<slug>.ntcsweb.com` needs nothing.
 */
const props = defineProps<{ school: School; canWrite: boolean }>()
const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

const hostname = ref('')
const addError = ref<string | undefined>(undefined)
const message = ref<{ text: string; ok: boolean } | null>(null)
const busy = ref<number | 'add' | null>(null)

async function run(key: number | 'add', action: () => Promise<unknown>): Promise<void> {
  busy.value = key
  message.value = null
  try {
    await action()
    emit('changed')
  } catch (e) {
    message.value = { text: e instanceof ApiRequestError ? (e.fieldError('domain') ?? e.message) : t('bo.common.failed'), ok: false }
  } finally {
    busy.value = null
  }
}

async function add(): Promise<void> {
  addError.value = undefined
  busy.value = 'add'
  try {
    await schoolsApi.addDomain(props.school.id, hostname.value)
    hostname.value = ''
    emit('changed')
  } catch (e) {
    addError.value = e instanceof ApiRequestError ? (e.fieldError('hostname') ?? e.message) : t('bo.common.failed')
  } finally {
    busy.value = null
  }
}

function verify(domain: SchoolDomain): Promise<void> {
  return run(domain.id, async () => {
    const result = await schoolsApi.verifyDomain(props.school.id, domain.id)
    message.value = { text: result.verified ? t('bo.schools.nowVerified') : t('bo.schools.notFoundYet'), ok: result.verified }
  })
}

async function remove(domain: SchoolDomain): Promise<void> {
  if (!(await confirmDialog.confirm({ message: t('bo.schools.removeDomainConfirm', { host: domain.hostname }), danger: true }))) return
  await run(domain.id, () => schoolsApi.removeDomain(props.school.id, domain.id))
}
</script>

<template>
  <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
    <h2 class="mb-1 font-semibold text-neutral-900">{{ t('bo.schools.domains') }}</h2>
    <p class="mb-4 text-sm text-neutral-600">{{ t('bo.schools.domainsHint', { sub: school.subdomain }) }}</p>
    <BaseAlert v-if="message" :variant="message.ok ? 'success' : 'warning'" class="mb-3">{{ message.text }}</BaseAlert>

    <ul class="space-y-3">
      <li v-for="domain in school.domains ?? []" :key="domain.id" class="rounded-lg border border-neutral-200 p-3">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-mono text-sm break-all text-neutral-900">{{ domain.hostname }}</span>
          <BaseBadge :variant="domain.verified ? 'success' : 'warning'">
            {{ domain.verified ? t('bo.schools.verified') : t('bo.schools.unverified') }}
          </BaseBadge>
          <BaseBadge v-if="domain.is_primary" variant="primary">{{ t('bo.schools.primary') }}</BaseBadge>
        </div>

        <div v-if="!domain.verified" class="mt-2 space-y-1 rounded bg-neutral-50 p-2 font-mono text-xs break-all text-neutral-700">
          <p>{{ t('bo.schools.dnsCname', { host: domain.hostname, target: school.domain_target }) }}</p>
          <p>{{ t('bo.schools.dnsTxt', { name: domain.txt_name ?? '', value: domain.txt_value ?? '' }) }}</p>
        </div>

        <div v-if="canWrite" class="mt-3 flex flex-wrap gap-2">
          <BaseButton v-if="!domain.verified" size="sm" variant="outline" :loading="busy === domain.id" @click="verify(domain)">
            {{ t('bo.schools.checkNow') }}
          </BaseButton>
          <BaseButton
            v-if="domain.verified && !domain.is_primary"
            size="sm"
            variant="ghost"
            @click="run(domain.id, () => schoolsApi.primaryDomain(school.id, domain.id))"
          >
            {{ t('bo.schools.makePrimary') }}
          </BaseButton>
          <BaseButton size="sm" variant="ghost" @click="remove(domain)">{{ t('bo.schools.removeDomain') }}</BaseButton>
        </div>
      </li>
    </ul>

    <form v-if="canWrite" class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-start" @submit.prevent="add">
      <div class="flex-1">
        <BaseInput v-model="hostname" :placeholder="'www.example.edu.kh'" :error="addError" required />
      </div>
      <BaseButton type="submit" :loading="busy === 'add'">{{ t('bo.schools.addDomain') }}</BaseButton>
    </form>
    <p class="mt-3 text-xs text-neutral-400">{{ t('bo.schools.tlsReminder') }}</p>
  </section>
</template>

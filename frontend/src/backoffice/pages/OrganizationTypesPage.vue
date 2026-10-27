<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

import { organizationTypeName, organizationTypesApi, type OrganizationType } from '../api'
import { useSession } from '../session'

/**
 * Organization types (School, Company, Organization, Ministry…). A customer
 * has one; its word replaces "school" on that customer's admin screens.
 */
const { t, locale } = useI18n()
const session = useSession()
const confirmDialog = useConfirmDialogStore()
const canWrite = computed(() => session.can('platform.school.write'))

const types = ref<OrganizationType[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

async function load(): Promise<void> {
  try {
    types.value = await organizationTypesApi.list()
    error.value = null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
}

const languages = [
  { key: 'en', label: 'English' },
  { key: 'en_plural', label: 'English (plural)' },
  { key: 'km', label: 'ខ្មែរ' },
  { key: 'zh', label: '中文' },
  { key: 'ja', label: '日本語' },
  { key: 'ko', label: '한국어' },
] as const
type WordKey = (typeof languages)[number]['key']

const blank = () => ({ code: '', sort_order: '0', words: { en: '', en_plural: '', km: '', zh: '', ja: '', ko: '' } as Record<WordKey, string> })
const formOpen = ref(false)
const editing = ref<OrganizationType | null>(null)
const form = ref(blank())
const saving = ref(false)
const formError = ref<ApiRequestError | null>(null)

function openForm(type: OrganizationType | null): void {
  editing.value = type
  formError.value = null
  form.value = type
    ? {
        code: type.code,
        sort_order: String(type.sort_order),
        words: Object.fromEntries(languages.map((l) => [l.key, type.words[l.key] ?? ''])) as Record<WordKey, string>,
      }
    : { ...blank(), sort_order: String(Math.max(0, ...types.value.map((o) => o.sort_order)) + 10) }
  formOpen.value = true
}

async function save(): Promise<void> {
  saving.value = true
  formError.value = null
  const body = {
    code: form.value.code.trim().toLowerCase(),
    sort_order: Number(form.value.sort_order) || 0,
    words: { ...Object.fromEntries(languages.map((l) => [l.key, form.value.words[l.key].trim()])), en: form.value.words.en.trim() },
  }
  try {
    if (editing.value) await organizationTypesApi.update(editing.value.id, body)
    else await organizationTypesApi.create(body)
    formOpen.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}

async function remove(type: OrganizationType): Promise<void> {
  if (!(await confirmDialog.confirm(t('bo.orgTypes.removeConfirm', { name: type.words.en })))) return
  notice.value = null
  try {
    const { deleted } = await organizationTypesApi.remove(type.id)
    if (!deleted) notice.value = t('bo.orgTypes.deactivated')
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

async function reactivate(type: OrganizationType): Promise<void> {
  try {
    await organizationTypesApi.update(type.id, { status: 'active' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

const otherWords = (type: OrganizationType) => [type.words.zh, type.words.ja, type.words.ko].filter(Boolean).join(' · ')

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">{{ t('bo.orgTypes.hint') }}</p>
      <BaseButton v-if="canWrite" @click="openForm(null)">{{ t('bo.orgTypes.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="info" class="mb-4">{{ notice }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else>
      <!-- Phone: cards -->
      <div class="space-y-3 sm:hidden">
        <section
          v-for="type in types"
          :key="type.id"
          class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]"
          :class="{ 'opacity-60': type.status === 'inactive' }"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <h2 class="font-semibold text-neutral-900">{{ organizationTypeName(type, locale) }}</h2>
              <p class="font-mono text-xs text-neutral-400">{{ type.code }}</p>
            </div>
            <BaseBadge v-if="type.status === 'inactive'" variant="warning">{{ t('bo.orgTypes.inactive') }}</BaseBadge>
          </div>
          <p class="mt-2 text-sm text-neutral-700">{{ type.words.en }} / {{ type.words.en_plural }} · {{ type.words.km }}</p>
          <p class="text-sm text-neutral-500">{{ otherWords(type) }}</p>
          <p class="mt-2 text-xs text-neutral-500">{{ t('bo.orgTypes.customers', { n: type.customers_count }) }}</p>
          <div v-if="canWrite" class="mt-3 flex flex-wrap gap-2 border-t border-neutral-100 pt-3">
            <BaseButton size="sm" variant="outline" @click="openForm(type)">{{ t('bo.orgTypes.edit') }}</BaseButton>
            <BaseButton v-if="type.status === 'inactive'" size="sm" variant="outline" @click="reactivate(type)">{{ t('bo.orgTypes.reactivate') }}</BaseButton>
            <BaseButton v-else-if="type.code !== 'school'" size="sm" variant="ghost" @click="remove(type)">{{ t('bo.orgTypes.remove') }}</BaseButton>
          </div>
        </section>
      </div>

      <!-- Desktop: table -->
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card] sm:block">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead class="bg-neutral-50 text-left text-xs font-medium tracking-wide text-neutral-500 uppercase">
            <tr>
              <th class="px-4 py-3">{{ t('bo.orgTypes.name') }}</th>
              <th class="px-4 py-3">{{ t('bo.orgTypes.code') }}</th>
              <th class="px-4 py-3">English</th>
              <th class="px-4 py-3">ខ្មែរ</th>
              <th class="px-4 py-3">中文 · 日本語 · 한국어</th>
              <th class="px-4 py-3 text-right">{{ t('bo.orgTypes.customersCol') }}</th>
              <th v-if="canWrite" class="px-4 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="type in types" :key="type.id" :class="{ 'opacity-60': type.status === 'inactive' }">
              <td class="px-4 py-3 font-medium text-neutral-900">
                {{ organizationTypeName(type, locale) }}
                <BaseBadge v-if="type.status === 'inactive'" variant="warning" class="ml-2">{{ t('bo.orgTypes.inactive') }}</BaseBadge>
              </td>
              <td class="px-4 py-3 font-mono text-xs text-neutral-500">{{ type.code }}</td>
              <td class="px-4 py-3 text-neutral-700">{{ type.words.en }} / {{ type.words.en_plural }}</td>
              <td class="px-4 py-3 text-neutral-700">{{ type.words.km }}</td>
              <td class="px-4 py-3 text-neutral-700">{{ otherWords(type) }}</td>
              <td class="px-4 py-3 text-right text-neutral-700">{{ type.customers_count }}</td>
              <td v-if="canWrite" class="px-4 py-3 text-right whitespace-nowrap">
                <BaseButton size="sm" variant="outline" @click="openForm(type)">{{ t('bo.orgTypes.edit') }}</BaseButton>
                <BaseButton v-if="type.status === 'inactive'" size="sm" variant="outline" class="ml-2" @click="reactivate(type)">{{ t('bo.orgTypes.reactivate') }}</BaseButton>
                <BaseButton v-else-if="type.code !== 'school'" size="sm" variant="ghost" class="ml-2" @click="remove(type)">{{ t('bo.orgTypes.remove') }}</BaseButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <BaseModal v-model="formOpen" :title="editing ? t('bo.orgTypes.edit') : t('bo.orgTypes.add')" size="lg">
      <form id="bo-org-type" class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="formError && !formError.errors" variant="danger">{{ formError.message }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput
            v-model="form.code"
            :label="t('bo.orgTypes.code')"
            :hint="t('bo.orgTypes.codeHint')"
            :error="formError?.fieldError('code')"
            :disabled="editing?.code === 'school'"
            required
          />
          <BaseInput v-model="form.sort_order" type="number" :label="t('bo.orgTypes.order')" :error="formError?.fieldError('sort_order')" />
        </div>
        <p class="text-sm text-neutral-500">{{ t('bo.orgTypes.wordsHint') }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput
            v-for="lang in languages"
            :key="lang.key"
            v-model="form.words[lang.key]"
            :label="lang.label"
            :error="formError?.fieldError(`words.${lang.key}`)"
            :required="lang.key === 'en'"
          />
        </div>
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="formOpen = false">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="bo-org-type" :loading="saving">{{ t('bo.common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

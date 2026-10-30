<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

import { helpApi, type HelpArticleRow, type HelpFaqRow, type HelpModuleOption } from '../../api'
import HelpEntryModal from '../../components/HelpEntryModal.vue'
import { useSession } from '../../session'

/**
 * Support → Help center (docs/backoffice/stage-13-help-support.md): the help
 * articles, FAQ and contacts every school sees in Help & Support. Writing
 * needs platform.support.help.
 */
const { t, locale } = useI18n()
const session = useSession()
const confirmDialog = useConfirmDialogStore()
const canWrite = computed(() => session.can('platform.support.help'))

type View = 'articles' | 'faq' | 'contact'
const view = ref<View>('articles')

const modules = ref<HelpModuleOption[]>([])
const articles = ref<HelpArticleRow[]>([])
const faqs = ref<HelpFaqRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [m, a, f, c] = await Promise.all([helpApi.modules(), helpApi.articles(), helpApi.faqs(), helpApi.contact()])
    modules.value = m
    articles.value = a
    faqs.value = f
    contact.value = { help_phone: c.phone ?? '', help_telegram: c.telegram ?? '', help_email: c.email ?? '', help_hours_en: c.hours_en ?? '', help_hours_km: c.hours_km ?? '' }
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
}

const moduleName = (code: string | null) => {
  if (!code) return t('bo.support.help.general')
  const m = modules.value.find((x) => x.code === code)
  return m ? (locale.value === 'km' && m.label_km ? m.label_km : m.label) : code
}
const both = (en: string | null, km: string | null) => (locale.value === 'km' ? km || en : en || km) || '—'
const missing = (en: string | null, km: string | null) => (!en ? 'EN' : !km ? 'KM' : null)

// --- Articles and FAQ -------------------------------------------------------------
const modalOpen = ref(false)
const editing = ref<HelpArticleRow | HelpFaqRow | null>(null)
const kind = computed(() => (view.value === 'faq' ? 'faq' : 'article'))

function openEntry(entry: HelpArticleRow | HelpFaqRow | null): void {
  editing.value = entry
  modalOpen.value = true
}

async function remove(entry: HelpArticleRow | HelpFaqRow): Promise<void> {
  const name = 'title_en' in entry ? both(entry.title_en, entry.title_km) : both(entry.question_en, entry.question_km)
  if (!(await confirmDialog.confirm({ message: t('bo.support.help.deleteConfirm', { name }), danger: true }))) return
  try {
    if ('title_en' in entry) await helpApi.deleteArticle(entry.id)
    else await helpApi.deleteFaq(entry.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

const rows = computed(() =>
  view.value === 'faq'
    ? faqs.value.map((f) => ({ entry: f as HelpArticleRow | HelpFaqRow, head: both(f.question_en, f.question_km), missing: missing(f.question_en, f.question_km), module: f.module, status: f.status, order: f.sort_order }))
    : articles.value.map((a) => ({ entry: a as HelpArticleRow | HelpFaqRow, head: both(a.title_en, a.title_km), missing: missing(a.title_en, a.title_km), module: a.module, status: a.status, order: a.sort_order })),
)

// --- Contacts ----------------------------------------------------------------------
const contact = ref({ help_phone: '', help_telegram: '', help_email: '', help_hours_en: '', help_hours_km: '' })
const savingContact = ref(false)
const contactSaved = ref(false)
const contactError = ref<ApiRequestError | null>(null)

async function saveContact(): Promise<void> {
  savingContact.value = true
  contactSaved.value = false
  contactError.value = null
  const v = contact.value
  try {
    await helpApi.saveContact({
      help_phone: v.help_phone.trim() || null,
      help_telegram: v.help_telegram.trim() || null,
      help_email: v.help_email.trim() || null,
      help_hours_en: v.help_hours_en.trim() || null,
      help_hours_km: v.help_hours_km.trim() || null,
    })
    contactSaved.value = true
  } catch (e) {
    contactError.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    savingContact.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('bo.support.help.intro') }}</p>

    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="inline-flex rounded-lg border border-neutral-300 bg-white p-0.5 shadow-sm" role="group">
        <button
          v-for="v in ['articles', 'faq', 'contact'] as const"
          :key="v"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="view === v ? 'bg-primary-500 text-secondary-900' : 'text-neutral-600 hover:bg-neutral-100'"
          :aria-pressed="view === v"
          @click="view = v"
        >
          {{ t(`bo.support.help.views.${v}`) }}
        </button>
      </div>
      <BaseButton v-if="canWrite && view !== 'contact'" class="sm:ml-auto" @click="openEntry(null)">{{ t(`bo.support.help.${kind}.add`) }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <p v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('bo.support.help.loading') }}</p>

    <template v-else-if="view !== 'contact'">
      <p v-if="rows.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t(`bo.support.help.${kind}.empty`) }}</p>
      <ul v-else class="divide-y divide-neutral-200 overflow-hidden rounded-xl border border-neutral-200 bg-white">
        <li v-for="r in rows" :key="r.entry.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
          <div class="min-w-0 flex-1">
            <button type="button" class="text-left font-medium text-neutral-900 hover:underline" @click="openEntry(r.entry)">{{ r.head }}</button>
            <p class="text-xs text-neutral-500">
              {{ moduleName(r.module) }} · {{ t('bo.support.help.orderN', { n: r.order }) }}
              <span v-if="r.missing" class="text-amber-700"> · {{ t('bo.support.help.missingLang', { lang: r.missing }) }}</span>
            </p>
          </div>
          <BaseBadge :variant="r.status === 'published' ? 'success' : 'neutral'">{{ t(`bo.support.help.status.${r.status}`) }}</BaseBadge>
          <div v-if="canWrite" class="flex gap-3 text-sm">
            <button type="button" class="font-medium text-secondary-700 hover:underline" @click="openEntry(r.entry)">{{ t('bo.support.help.editBtn') }}</button>
            <button type="button" class="font-medium text-red-600 hover:underline" @click="remove(r.entry)">{{ t('bo.support.help.deleteBtn') }}</button>
          </div>
        </li>
      </ul>
    </template>

    <form v-else class="max-w-2xl space-y-4 rounded-xl border border-neutral-200 bg-white p-4" novalidate @submit.prevent="saveContact">
      <p class="text-sm text-neutral-600">{{ t('bo.support.help.contactIntro') }}</p>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="contact.help_phone" :disabled="!canWrite" :label="t('bo.support.help.phone')" placeholder="012 345 678" :error="contactError?.errors?.help_phone?.[0]" />
        <BaseInput v-model="contact.help_telegram" :disabled="!canWrite" :label="t('bo.support.help.telegram')" placeholder="@newtechkh" :error="contactError?.errors?.help_telegram?.[0]" />
        <BaseInput v-model="contact.help_email" :disabled="!canWrite" type="email" :label="t('bo.support.help.email')" placeholder="support@newtechkh.com" :error="contactError?.errors?.help_email?.[0]" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="contact.help_hours_en" :disabled="!canWrite" :label="t('bo.support.help.hoursEn')" placeholder="Mon–Sat, 8:00–17:00" />
        <BaseInput v-model="contact.help_hours_km" :disabled="!canWrite" :label="t('bo.support.help.hoursKm')" placeholder="ច័ន្ទ–សៅរ៍ ៨:០០–១៧:០០" />
      </div>
      <BaseAlert v-if="contactSaved" variant="success">{{ t('bo.common.saved') }}</BaseAlert>
      <BaseAlert v-if="contactError && !contactError.errors" variant="danger">{{ contactError.message }}</BaseAlert>
      <BaseButton v-if="canWrite" type="submit" :loading="savingContact">{{ t('bo.common.save') }}</BaseButton>
    </form>

    <HelpEntryModal v-model="modalOpen" :kind="kind" :entry="editing" :modules="modules" @saved="load" />
  </div>
</template>

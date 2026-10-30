<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'

import { helpApi, type HelpArticleRow, type HelpFaqRow, type HelpModuleOption, type HelpStatus } from '../api'

/**
 * Write or edit one help article or FAQ question (docs/backoffice/stage-13-help-support.md):
 * its module (or general), English and Khmer text, draft or published, order.
 */
const props = defineProps<{
  modelValue: boolean
  kind: 'article' | 'faq'
  entry: HelpArticleRow | HelpFaqRow | null
  modules: HelpModuleOption[]
}>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; saved: [] }>()

const { t, locale } = useI18n()

const form = ref({ module: '', status: 'draft' as HelpStatus, sort_order: '', head_en: '', head_km: '', text_en: '', text_km: '' })
const saving = ref(false)
const error = ref<ApiRequestError | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    error.value = null
    const e = props.entry
    const a = e && 'title_en' in e ? e : null
    const f = e && 'question_en' in e ? e : null
    form.value = {
      module: e?.module ?? '',
      status: e?.status ?? 'draft',
      sort_order: e ? String(e.sort_order) : '',
      head_en: a?.title_en ?? f?.question_en ?? '',
      head_km: a?.title_km ?? f?.question_km ?? '',
      text_en: a?.body_en ?? f?.answer_en ?? '',
      text_km: a?.body_km ?? f?.answer_km ?? '',
    }
  },
)

const moduleOptions = computed(() => [
  { value: '', label: t('bo.support.help.general') },
  ...props.modules.map((m) => ({ value: m.code, label: locale.value === 'km' && m.label_km ? m.label_km : m.label })),
])
const statusOptions = computed(() => (['draft', 'published'] as const).map((s) => ({ value: s, label: t(`bo.support.help.status.${s}`) })))

const isArticle = computed(() => props.kind === 'article')
const fieldErr = (name: string) => error.value?.errors?.[name]?.[0]

const textareaClass =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none'

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  const v = form.value
  const common = { module: v.module || null, status: v.status, // Blank on a new one: the server puts it last.
    sort_order: String(v.sort_order).trim() === '' ? (props.entry?.sort_order ?? null) : Number(v.sort_order),
  }
  const clean = (s: string) => s.trim() || null
  try {
    if (isArticle.value) {
      await helpApi.saveArticle(props.entry?.id ?? null, { ...common, title_en: clean(v.head_en), title_km: clean(v.head_km), body_en: clean(v.text_en), body_km: clean(v.text_km) })
    } else {
      await helpApi.saveFaq(props.entry?.id ?? null, { ...common, question_en: clean(v.head_en), question_km: clean(v.head_km), answer_en: clean(v.text_en), answer_km: clean(v.text_km) })
    }
    emit('saved')
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    size="lg"
    :title="t(`bo.support.help.${kind}.${entry ? 'edit' : 'add'}`)"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="help-entry-form" class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="error && !error.errors" variant="danger">{{ error.message }}</BaseAlert>

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseSelect v-model="form.module" :options="moduleOptions" :label="t('bo.support.help.module')" :error="fieldErr('module')" />
        <BaseSelect v-model="form.status" :options="statusOptions" :label="t('bo.support.help.statusLabel')" />
        <BaseInput v-model="form.sort_order" type="number" :label="t('bo.support.help.order')" :hint="t('bo.support.help.orderHint')" />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.head_en" :label="t(`bo.support.help.${kind}.headEn`)" :error="fieldErr(isArticle ? 'title_en' : 'question_en')" />
        <BaseInput v-model="form.head_km" :label="t(`bo.support.help.${kind}.headKm`)" :error="fieldErr(isArticle ? 'title_km' : 'question_km')" />
      </div>

      <p v-if="isArticle" class="rounded-lg bg-neutral-50 px-3 py-2 text-xs text-neutral-600">{{ t('bo.support.help.formatHint') }}</p>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="help-text-en" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t(`bo.support.help.${kind}.textEn`) }}</label>
          <textarea id="help-text-en" v-model="form.text_en" :rows="isArticle ? 14 : 5" :class="textareaClass" />
          <p v-if="fieldErr(isArticle ? 'body_en' : 'answer_en')" class="mt-1 text-xs text-red-600">{{ fieldErr(isArticle ? 'body_en' : 'answer_en') }}</p>
        </div>
        <div>
          <label for="help-text-km" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t(`bo.support.help.${kind}.textKm`) }}</label>
          <textarea id="help-text-km" v-model="form.text_km" :rows="isArticle ? 14 : 5" :class="textareaClass" />
        </div>
      </div>
      <p class="text-xs text-neutral-500">{{ t('bo.support.help.bothLanguages') }}</p>
    </form>

    <template #footer>
      <div class="flex w-full flex-wrap justify-end gap-2">
        <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="help-entry-form" :loading="saving">{{ t('bo.common.save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>

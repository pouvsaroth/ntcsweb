<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { type Language, languagesService } from '@/services/languages'
import type { LookupCategory } from '@/services/lookupCategories'
import { type LookupValue, lookupValuesService } from '@/services/lookupValues'
import { ApiRequestError } from '@/types/api'

/**
 * One form, every configured language at once — editing a value's English,
 * Khmer, Chinese, Korean, and Japanese name/description together, matching
 * the spec's own suggested UI rather than a separate translation-per-row
 * screen. Only the platform's default language is required.
 */
const props = defineProps<{
  modelValue: boolean
  category: LookupCategory
  value?: LookupValue | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.value != null)

const form = reactive({
  code: '',
  is_active: true,
  sort_order: 0,
  translations: {} as Record<string, { name: string; description: string }>,
})

const languages = ref<Language[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

function resetTranslations() {
  const translations: Record<string, { name: string; description: string }> = {}
  for (const language of languages.value) {
    translations[language.code] = {
      name: props.value?.translations?.[language.code]?.name ?? '',
      description: props.value?.translations?.[language.code]?.description ?? '',
    }
  }
  form.translations = translations
}

onMounted(async () => {
  languages.value = await languagesService.listAll()
  resetTranslations()
})

watch(
  () => [props.modelValue, props.value] as const,
  ([open]) => {
    if (!open) return

    form.code = props.value?.code ?? ''
    form.is_active = props.value?.is_active ?? true
    form.sort_order = props.value?.sort_order ?? 0
    resetTranslations()
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input = { ...form, lookup_category_id: props.category.id }

    if (isEditing.value) {
      await lookupValuesService.update(props.value!.id, input)
    } else {
      await lookupValuesService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.lookupValues.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    size="lg"
    :title="isEditing ? t('admin.lookupValues.editTitle') : t('admin.lookupValues.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
        {{ t('admin.lookupValues.category') }}: <span class="font-medium text-neutral-800">{{ category.code }} — {{ category.name }}</span>
      </div>

      <BaseInput v-model="form.code" required :label="t('admin.lookupValues.code')" :hint="t('admin.lookupValues.codeHint')" :error="errors.code?.[0]" />

      <div class="space-y-4 border-t border-neutral-200 pt-4">
        <h3 class="text-sm font-semibold text-neutral-800">{{ t('admin.lookupValues.translationsSection') }}</h3>

        <div v-for="language in languages" :key="language.code" class="rounded-lg border border-neutral-200 p-3">
          <p class="mb-2 text-xs font-medium uppercase tracking-wide text-neutral-500">{{ language.native_name }} ({{ language.code }})</p>
          <div class="grid gap-3 sm:grid-cols-2">
            <BaseInput
              v-model="form.translations[language.code].name"
              :label="t('admin.lookupValues.translationName')"
              :error="errors[`translations.${language.code}.name`]?.[0]"
            />
            <BaseInput
              v-model="form.translations[language.code].description"
              :label="t('admin.lookupValues.translationDescription')"
              :error="errors[`translations.${language.code}.description`]?.[0]"
            />
          </div>
        </div>
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.lookupValues.statusActive') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

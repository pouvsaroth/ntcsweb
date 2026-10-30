<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { observationsService, type ObservationForm, type ObservationFormCriterion } from '@/services/professionalDevelopment'
import { ApiRequestError } from '@/types/api'

/**
 * Make or edit a classroom observation form: criteria rated 1–5 stars,
 * optionally grouped by section. Past observations keep their own copy.
 */
const props = defineProps<{ modelValue: boolean; form: ObservationForm | null }>()
const emit = defineEmits<{ 'update:modelValue': [boolean]; saved: [] }>()

const { t } = useI18n()

const name = ref('')
const description = ref('')
const isActive = ref(true)
const criteria = ref<ObservationFormCriterion[]>([])
const errors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const saving = ref(false)

// A starting set for a new form, in the user's language: [section, criterion] keys under proDev.forms.starter.
const STARTER: [string, string][] = [
  ['planning', 'c1'],
  ['delivery', 'c2'],
  ['delivery', 'c3'],
  ['engagement', 'c4'],
  ['management', 'c5'],
  ['assessment', 'c6'],
]
const starter = (): ObservationFormCriterion[] =>
  STARTER.map(([s, c]) => ({ section: t(`proDev.forms.starter.${s}`), criterion: t(`proDev.forms.starter.${c}`) }))

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    errors.value = {}
    error.value = null
    name.value = props.form?.name ?? ''
    description.value = props.form?.description ?? ''
    isActive.value = props.form?.is_active ?? true
    criteria.value = props.form ? props.form.criteria.map((c) => ({ section: c.section, criterion: c.criterion })) : starter()
  },
)

function add() {
  const last = criteria.value[criteria.value.length - 1]
  criteria.value.push({ section: last?.section ?? '', criterion: '' })
}
function remove(i: number) {
  criteria.value.splice(i, 1)
}
function move(i: number, by: number) {
  const j = i + by
  if (j < 0 || j >= criteria.value.length) return
  const list = criteria.value
  ;[list[i], list[j]] = [list[j]!, list[i]!]
}

const fieldClass =
  'block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'

async function save() {
  saving.value = true
  errors.value = {}
  error.value = null
  const input = {
    name: name.value.trim(),
    description: description.value.trim() || null,
    is_active: isActive.value,
    criteria: criteria.value.filter((c) => c.criterion.trim()).map((c) => ({ section: c.section?.trim() || null, criterion: c.criterion.trim() })),
  }
  try {
    if (props.form) await observationsService.updateForm(props.form.id, input)
    else await observationsService.createForm(input)
    emit('saved')
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else error.value = e instanceof ApiRequestError ? e.message : t('proDev.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" size="lg" :title="form ? t('proDev.forms.editTitle') : t('proDev.forms.addTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form id="observation-form-editor" class="space-y-4" novalidate @submit.prevent="save">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseInput v-model="name" required :label="t('proDev.forms.name')" :placeholder="t('proDev.forms.namePlaceholder')" :error="errors.name?.[0]" />
      <div>
        <label for="observation-form-description" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('proDev.forms.description') }}</label>
        <textarea id="observation-form-description" v-model="description" rows="2" :class="fieldClass" />
      </div>
      <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="isActive" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-200" />
        {{ t('proDev.forms.active') }}
      </label>

      <div>
        <p class="mb-1 text-sm font-medium text-neutral-700">{{ t('proDev.forms.criteria') }} <span class="text-danger-600">*</span></p>
        <p class="mb-2 text-xs text-neutral-500">{{ t('proDev.forms.criteriaHint') }}</p>
        <ul class="space-y-2">
          <li v-for="(c, i) in criteria" :key="i" class="rounded-lg border border-neutral-200 p-2">
            <div class="grid gap-2 sm:grid-cols-[10rem_1fr_auto]">
              <input v-model="c.section" :placeholder="t('proDev.forms.section')" :aria-label="t('proDev.forms.section')" :class="fieldClass" />
              <input v-model="c.criterion" :placeholder="t('proDev.forms.criterion')" :aria-label="t('proDev.forms.criterion')" :class="fieldClass" />
              <div class="flex items-center justify-end gap-1 text-neutral-500">
                <button type="button" class="rounded px-2 py-1 hover:bg-neutral-100" :aria-label="t('proDev.forms.moveUp')" @click="move(i, -1)">↑</button>
                <button type="button" class="rounded px-2 py-1 hover:bg-neutral-100" :aria-label="t('proDev.forms.moveDown')" @click="move(i, 1)">↓</button>
                <button type="button" class="rounded px-2 py-1 text-danger-600 hover:bg-red-50" :aria-label="t('proDev.delete')" @click="remove(i)">✕</button>
              </div>
            </div>
          </li>
        </ul>
        <p v-if="errors.criteria?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.criteria[0] }}</p>
        <button type="button" class="mt-2 text-sm font-medium text-secondary-700 hover:underline" @click="add">+ {{ t('proDev.forms.addCriterion') }}</button>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="observation-form-editor" :loading="saving">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

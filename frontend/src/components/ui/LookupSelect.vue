<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseSelect from '@/components/ui/BaseSelect.vue'
import { type LookupOption, lookupsService } from '@/services/lookups'

/**
 * The reusable dropdown every module should use for a Base Data category
 * (Gender, Guardian Type, Book Type, Payment Method, ...) instead of
 * hardcoding options in Vue — see backend/app/Services/BaseData/.
 *
 * `v-model` carries the lookup value's stable `code` by default (matching
 * how existing free-text columns like Student.gender are already stored),
 * or its numeric `id` when `value-field="id"` — never the translated
 * display text. Changing the app's language re-fetches and re-renders the
 * label; the underlying selected code/id never changes.
 *
 * If the current `modelValue` isn't among the fetched options (a value
 * saved before this field adopted LookupSelect, or from a category an
 * admin has since deactivated), it's kept as its own selectable option
 * instead of silently blanking out — see `selectOptions` below.
 */
const props = withDefaults(
  defineProps<{
    modelValue: string
    /** The Lookup Category's stable code, e.g. "GENDER". */
    category: string
    valueField?: 'code' | 'id'
    label?: string
    placeholder?: string
    error?: string
    hint?: string
    required?: boolean
    disabled?: boolean
  }>(),
  {
    valueField: 'code',
    label: undefined,
    placeholder: undefined,
    error: undefined,
    hint: undefined,
    required: false,
    disabled: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { locale, t } = useI18n()

const options = ref<LookupOption[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)

/** category:lang -> options, module-level so every LookupSelect for the same category on one page shares a fetch. */
const cache = new Map<string, LookupOption[]>()

const selectOptions = computed(() => {
  const mapped = options.value.map((option) => ({
    value: props.valueField === 'id' ? String(option.id) : option.code,
    label: option.name,
  }))

  // A value already stored before this field used LookupSelect (e.g. free
  // text typed before a category existed, or a value from a category an
  // admin has since deactivated) must stay visible and selected rather than
  // silently reverting to blank — the raw stored value is shown as its own
  // label since no translation is known for it. This never happens for a
  // value the fetched list already contains.
  if (props.modelValue && !mapped.some((option) => option.value === props.modelValue)) {
    mapped.unshift({ value: props.modelValue, label: props.modelValue })
  }

  return mapped
})

async function load() {
  const key = `${props.category}:${locale.value}`
  const cached = cache.get(key)

  if (cached) {
    options.value = cached
    return
  }

  loading.value = true
  loadError.value = null

  try {
    const result = await lookupsService.values(props.category, locale.value)
    cache.set(key, result)
    options.value = result
  } catch {
    loadError.value = t('common.lookupLoadFailed')
  } finally {
    loading.value = false
  }
}

watch(() => [props.category, locale.value] as const, load)

onMounted(load)
</script>

<template>
  <BaseSelect
    :model-value="modelValue"
    :options="selectOptions"
    :label="label"
    :placeholder="loading ? t('common.loading') : placeholder"
    :error="error || loadError || undefined"
    :hint="hint"
    :required="required"
    :disabled="disabled || loading"
    @update:model-value="emit('update:modelValue', $event)"
  />
</template>

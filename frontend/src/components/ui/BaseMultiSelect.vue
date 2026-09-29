<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * A checkbox dropdown for "pick any of these" filters (e.g. invoice status:
 * Unpaid + Partially Paid at once) — BaseSelect only ever lets you pick one.
 * Same label/placeholder/error/hint contract as BaseSelect, but `modelValue`
 * is a `string[]` and there's no `required` (an empty selection is always a
 * valid "no filter" state here, never a validation error).
 */
interface Option {
  value: string
  label: string
}

interface Props {
  modelValue: string[]
  options: Option[]
  label?: string
  placeholder?: string
  error?: string
  hint?: string
  disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  label: undefined,
  placeholder: undefined,
  error: undefined,
  hint: undefined,
  disabled: false,
})

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()

const { t } = useI18n()
const id = useId()
const open = ref(false)

const selectedLabels = computed(() => props.options.filter((option) => props.modelValue.includes(option.value)).map((option) => option.label))

const summary = computed(() => {
  if (selectedLabels.value.length === 0) return props.placeholder ?? ''
  if (selectedLabels.value.length <= 2) return selectedLabels.value.join(', ')
  return t('common.selectedCount', { count: selectedLabels.value.length })
})

function toggle(value: string) {
  const next = new Set(props.modelValue)
  next.has(value) ? next.delete(value) : next.add(value)
  emit('update:modelValue', Array.from(next))
}

function clear() {
  emit('update:modelValue', [])
}

function onDocumentClick(event: MouseEvent) {
  if (!(event.target as Element).closest(`[data-multiselect="${id}"]`)) open.value = false
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onUnmounted(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
  <div :data-multiselect="id">
    <label v-if="label" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ label }}</label>

    <div class="relative">
      <button
        type="button"
        :disabled="disabled"
        :aria-expanded="open"
        class="flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left text-sm shadow-sm transition-colors focus:outline-none focus:ring-2 disabled:bg-neutral-100 disabled:text-neutral-500"
        :class="
          error
            ? 'border-danger-400 focus:border-danger-500 focus:ring-danger-200'
            : 'border-neutral-300 focus:border-primary-500 focus:ring-primary-200'
        "
        @click="open = !open"
      >
        <span class="truncate" :class="selectedLabels.length === 0 ? 'text-neutral-400' : 'text-neutral-900'">{{ summary }}</span>
        <svg class="h-4 w-4 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
        </svg>
      </button>

      <div v-show="open" class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-lg">
        <ul class="max-h-60 overflow-auto py-1 text-sm">
          <li
            v-for="option in options"
            :key="option.value"
            class="flex cursor-pointer items-center gap-2 px-3 py-2 hover:bg-neutral-50"
            @mousedown.prevent="toggle(option.value)"
          >
            <input
              type="checkbox"
              class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              :checked="modelValue.includes(option.value)"
              @click.stop.prevent
            />
            <span class="text-neutral-800">{{ option.label }}</span>
          </li>
        </ul>
        <button
          v-if="modelValue.length > 0"
          type="button"
          class="block w-full border-t border-neutral-100 px-3 py-2 text-left text-sm font-medium text-primary-700 hover:bg-neutral-50"
          @mousedown.prevent="clear"
        >
          {{ t('common.clearSelection') }}
        </button>
      </div>
    </div>

    <p v-if="error" class="mt-1.5 text-sm text-danger-600">{{ error }}</p>
    <p v-else-if="hint" class="mt-1.5 text-sm text-neutral-500">{{ hint }}</p>
  </div>
</template>

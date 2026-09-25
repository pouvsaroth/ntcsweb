<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * A BaseSelect you can type into: the text box filters the options as you
 * type (case-insensitive, matching the label or the optional `hint`, e.g. an
 * employee code), arrow keys + Enter pick one, Escape cancels. Same
 * v-model/props contract as BaseSelect, so swapping one for the other is a
 * one-word change. Filtering is client-side — meant for lists already
 * loaded in full (a few hundred options at most), not server-side search.
 *
 * `placeholder` doubles as the "nothing selected" choice: when set and the
 * field isn't `required`, a clear (×) button resets the value to ''.
 */
interface Option {
  value: string
  label: string
  hint?: string
}

interface Props {
  modelValue: string
  options: Option[]
  label?: string
  placeholder?: string
  error?: string
  hint?: string
  required?: boolean
  disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  label: undefined,
  placeholder: undefined,
  error: undefined,
  hint: undefined,
  required: false,
  disabled: false,
})

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { t } = useI18n()
const id = useId()
const listId = `${id}-list`

const open = ref(false)
const query = ref('')
const activeIndex = ref(0)
const input = ref<HTMLInputElement | null>(null)
const list = ref<HTMLUListElement | null>(null)

const selected = computed(() => props.options.find((option) => option.value === props.modelValue) ?? null)

const filtered = computed(() => {
  const needle = query.value.trim().toLocaleLowerCase()
  if (!needle) return props.options

  return props.options.filter(
    (option) => option.label.toLocaleLowerCase().includes(needle) || (option.hint?.toLocaleLowerCase().includes(needle) ?? false),
  )
})

/** Closed: the selected option's label. Open: whatever the user is typing. */
const inputText = computed(() => (open.value ? query.value : (selected.value?.label ?? '')))

watch(filtered, () => {
  activeIndex.value = 0
})

function openList() {
  if (props.disabled || open.value) return
  open.value = true
  query.value = ''
  const index = filtered.value.findIndex((option) => option.value === props.modelValue)
  activeIndex.value = Math.max(index, 0)
  nextTick(scrollActiveIntoView)
}

function close() {
  open.value = false
  query.value = ''
}

function choose(option: Option) {
  emit('update:modelValue', option.value)
  close()
  input.value?.blur()
}

function clear() {
  emit('update:modelValue', '')
  close()
}

function onInput(event: Event) {
  if (!open.value) openList()
  query.value = (event.target as HTMLInputElement).value
}

function move(step: number) {
  if (!open.value) return openList()
  if (filtered.value.length === 0) return
  activeIndex.value = (activeIndex.value + step + filtered.value.length) % filtered.value.length
  nextTick(scrollActiveIntoView)
}

function onEnter() {
  const option = filtered.value[activeIndex.value]
  if (open.value && option) choose(option)
}

function scrollActiveIntoView() {
  list.value?.querySelector<HTMLElement>(`[data-index="${activeIndex.value}"]`)?.scrollIntoView({ block: 'nearest' })
}
</script>

<template>
  <div>
    <label v-if="label" :for="id" class="mb-1.5 block text-sm font-medium text-neutral-700">
      {{ label }}
      <span v-if="required" class="text-danger-600">*</span>
    </label>

    <div class="relative">
      <input
        :id="id"
        ref="input"
        type="text"
        role="combobox"
        autocomplete="off"
        :value="inputText"
        :placeholder="open ? t('common.searchPlaceholder') : placeholder"
        :disabled="disabled"
        :required="required && !modelValue"
        :aria-expanded="open"
        :aria-controls="listId"
        :aria-activedescendant="open && filtered[activeIndex] ? `${listId}-${activeIndex}` : undefined"
        :aria-invalid="Boolean(error)"
        class="block w-full rounded-lg border py-2 pl-3 pr-14 text-sm text-neutral-900 shadow-sm transition-colors focus:outline-none focus:ring-2 disabled:bg-neutral-100 disabled:text-neutral-500"
        :class="
          error
            ? 'border-danger-400 focus:border-danger-500 focus:ring-danger-200'
            : 'border-neutral-300 focus:border-primary-500 focus:ring-primary-200'
        "
        @focus="openList"
        @click="openList"
        @input="onInput"
        @blur="close"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="onEnter"
        @keydown.esc="close"
      />

      <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center gap-1 pr-2 text-neutral-400">
        <button
          v-if="modelValue && placeholder && !required && !disabled"
          type="button"
          class="pointer-events-auto rounded p-0.5 hover:text-neutral-700"
          :aria-label="placeholder"
          @mousedown.prevent="clear"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
        </svg>
      </div>

      <ul
        v-show="open"
        :id="listId"
        ref="list"
        role="listbox"
        class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-neutral-200 bg-white py-1 text-sm shadow-lg"
      >
        <li
          v-for="(option, index) in filtered"
          :id="`${listId}-${index}`"
          :key="option.value"
          role="option"
          :data-index="index"
          :aria-selected="option.value === modelValue"
          class="flex cursor-pointer items-baseline justify-between gap-3 px-3 py-2"
          :class="[index === activeIndex ? 'bg-primary-50 text-primary-800' : 'text-neutral-800', option.value === modelValue ? 'font-semibold' : '']"
          @mousedown.prevent="choose(option)"
          @mouseenter="activeIndex = index"
        >
          <span class="truncate">{{ option.label }}</span>
          <span v-if="option.hint" class="shrink-0 text-xs text-neutral-400">{{ option.hint }}</span>
        </li>
        <li v-if="filtered.length === 0" class="px-3 py-2 text-neutral-500">{{ t('common.noResults') }}</li>
      </ul>
    </div>

    <p v-if="error" class="mt-1.5 text-sm text-danger-600">{{ error }}</p>
    <p v-else-if="hint" class="mt-1.5 text-sm text-neutral-500">{{ hint }}</p>
  </div>
</template>

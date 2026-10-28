<script setup lang="ts">
import { computed, ref, watch } from 'vue'

/**
 * The house date box: always shows dd-mm-yyyy, whatever the browser's or
 * computer's language (a native <input type="date"> shows mm/dd/yyyy on an
 * English-US machine). The value going in and out stays ISO "yyyy-mm-dd"
 * (with "Thh:mm" when `withTime`), so callers and the API never change.
 *
 * Type the digits (dashes are added for you) or press the calendar button,
 * which opens the browser's own date picker.
 */
const props = withDefaults(
  defineProps<{
    modelValue: string | null | undefined
    id?: string
    disabled?: boolean
    required?: boolean
    invalid?: boolean
    /** Also pick a time: the value is "yyyy-mm-ddThh:mm" (datetime-local). */
    withTime?: boolean
    min?: string
    max?: string
    describedBy?: string
    inputClass?: string
  }>(),
  { id: undefined, disabled: false, required: false, invalid: false, withTime: false, min: undefined, max: undefined, describedBy: undefined, inputClass: '' },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const datePart = computed(() => (props.modelValue ?? '').slice(0, 10))
const timePart = computed(() => (props.withTime ? (props.modelValue ?? '').slice(11, 16) : ''))

/** "2026-10-27" → "27-10-2026"; anything else → "". */
function toDisplay(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)
  return m ? `${m[3]}-${m[2]}-${m[1]}` : ''
}

/** "27-10-2026" (or 27/10/2026, 27.10.2026, 27102026) → "2026-10-27"; null when not a real date. */
function toIso(text: string): string | null {
  const digits = text.replace(/\D/g, '')
  if (digits.length !== 8) return null
  const [d, mo, y] = [Number(digits.slice(0, 2)), Number(digits.slice(2, 4)), Number(digits.slice(4, 8))]
  const date = new Date(y, mo - 1, d)
  if (y < 1000 || date.getFullYear() !== y || date.getMonth() !== mo - 1 || date.getDate() !== d) return null
  return `${digits.slice(4, 8)}-${digits.slice(2, 4)}-${digits.slice(0, 2)}`
}

const text = ref(toDisplay(datePart.value))
watch(datePart, (iso) => {
  // Only overwrite what is being typed when the value really changed from outside.
  if (toIso(text.value) !== iso) text.value = toDisplay(iso)
})

function commit(iso: string) {
  if (iso === '') {
    emit('update:modelValue', '')
    return
  }
  emit('update:modelValue', props.withTime ? `${iso}T${timePart.value || '00:00'}` : iso)
}

/** Keep only digits and put the dashes in: "27102026" → "27-10-2026". */
function onType(event: Event) {
  const input = event.target as HTMLInputElement
  const digits = input.value.replace(/\D/g, '').slice(0, 8)
  const formatted = [digits.slice(0, 2), digits.slice(2, 4), digits.slice(4, 8)].filter(Boolean).join('-')
  text.value = formatted
  input.value = formatted

  if (digits.length === 0) commit('')
  else {
    const iso = toIso(formatted)
    if (iso) commit(iso)
  }
}

/** Leaving a half-typed or impossible date puts the last good value back. */
function onBlur() {
  if (text.value === '') return
  if (toIso(text.value) === null) text.value = toDisplay(datePart.value)
}

const picker = ref<HTMLInputElement | null>(null)
function openPicker() {
  const el = picker.value
  if (!el || props.disabled) return
  try {
    el.showPicker()
  } catch {
    el.focus()
    el.click()
  }
}

function onPicked(event: Event) {
  const iso = (event.target as HTMLInputElement).value
  text.value = toDisplay(iso)
  commit(iso)
}

function onTime(event: Event) {
  const time = (event.target as HTMLInputElement).value
  if (datePart.value) emit('update:modelValue', `${datePart.value}T${time || '00:00'}`)
}

const borderClass = computed(() =>
  props.invalid ? 'border-danger-400 focus:border-danger-500 focus:ring-danger-200' : 'border-neutral-300 focus:border-primary-500 focus:ring-primary-200',
)
// No width here: the date box fills the row (w-full), the time box stays w-32.
const boxClass =
  'block rounded-lg border px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:outline-none focus:ring-2 disabled:bg-neutral-100 disabled:text-neutral-500'
</script>

<template>
  <div class="flex gap-2">
    <div class="relative min-w-0 flex-1">
      <input
        :id="id"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        placeholder="dd-mm-yyyy"
        maxlength="10"
        :value="text"
        :disabled="disabled"
        :required="required"
        :aria-invalid="invalid"
        :aria-describedby="describedBy"
        :class="[boxClass, borderClass, inputClass, 'w-full pr-10']"
        @input="onType"
        @blur="onBlur"
      />
      <button
        type="button"
        tabindex="-1"
        :disabled="disabled"
        class="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-neutral-400 hover:text-primary-600 disabled:cursor-not-allowed disabled:hover:text-neutral-400"
        :aria-label="'Calendar'"
        @click="openPicker"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
        </svg>
      </button>
      <!-- The browser's own calendar, opened by the button; never shown itself. -->
      <input
        ref="picker"
        type="date"
        tabindex="-1"
        aria-hidden="true"
        class="pointer-events-none absolute bottom-0 right-0 h-px w-px border-0 p-0 opacity-0"
        :value="datePart"
        :min="min"
        :max="max"
        @change="onPicked"
      />
    </div>
    <input
      v-if="withTime"
      type="time"
      :value="timePart"
      :disabled="disabled || !datePart"
      :required="required"
      :class="[boxClass, borderClass, 'w-32 shrink-0']"
      @change="onTime"
    />
  </div>
</template>

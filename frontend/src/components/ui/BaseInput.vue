<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'

import DateInput from './DateInput.vue'

interface Props {
  modelValue: string
  label?: string
  type?: string
  placeholder?: string
  error?: string
  hint?: string
  required?: boolean
  disabled?: boolean
  autocomplete?: string
}

const props = withDefaults(defineProps<Props>(), {
  label: undefined,
  type: 'text',
  placeholder: undefined,
  error: undefined,
  hint: undefined,
  required: false,
  disabled: false,
  autocomplete: undefined,
})

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const id = useId()

// Password boxes get our own eye button (always there, unlike the browser's).
const { t, te } = useI18n()
const isPassword = computed(() => props.type === 'password')
const showPassword = ref(false)
const inputType = computed(() => (isPassword.value && showPassword.value ? 'text' : props.type))
const eyeLabel = computed(() => {
  const key = showPassword.value ? 'common.hidePassword' : 'common.showPassword'
  return te(key) ? t(key) : showPassword.value ? 'Hide password' : 'Show password'
})
const describedBy = computed(() => (props.error ? `${id}-error` : props.hint ? `${id}-hint` : undefined))
</script>

<template>
  <div>
    <label v-if="label" :for="id" class="mb-1.5 block text-sm font-medium text-neutral-700">
      {{ label }}
      <span v-if="required" class="text-danger-600">*</span>
    </label>

    <!-- Dates always show dd-mm-yyyy, never the browser's own format. -->
    <DateInput
      v-if="type === 'date' || type === 'datetime-local'"
      :id="id"
      :model-value="modelValue"
      :with-time="type === 'datetime-local'"
      :disabled="disabled"
      :required="required"
      :invalid="Boolean(error)"
      :described-by="describedBy"
      @update:model-value="emit('update:modelValue', $event)"
    />
    <div v-else class="relative">
      <input
        :id="id"
        :type="inputType"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :required="required"
        :autocomplete="autocomplete"
        :aria-invalid="Boolean(error)"
        :aria-describedby="describedBy"
        class="block w-full rounded-lg border px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:outline-none focus:ring-2 disabled:bg-neutral-100 disabled:text-neutral-500"
        :class="
          error
            ? 'border-danger-400 focus:border-danger-500 focus:ring-danger-200'
            : 'border-neutral-300 focus:border-primary-500 focus:ring-primary-200'
        "
        :style="isPassword ? 'padding-right: 2.5rem' : undefined"
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      />
      <button
        v-if="isPassword"
        type="button"
        :disabled="disabled"
        :aria-label="eyeLabel"
        :title="eyeLabel"
        :aria-pressed="showPassword"
        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-neutral-400 hover:text-neutral-700 focus:text-primary-600 focus:outline-none disabled:cursor-not-allowed"
        @click="showPassword = !showPassword"
      >
        <svg v-if="!showPassword" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
        </svg>
      </button>
    </div>

    <p v-if="error" :id="`${id}-error`" class="mt-1.5 text-sm text-danger-600">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-hint`" class="mt-1.5 text-sm text-neutral-500">{{ hint }}</p>
  </div>
</template>

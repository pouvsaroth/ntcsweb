<script setup lang="ts">
import { onBeforeUnmount, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { referralsService, type ReferralStudent } from '@/services/referrals'

/**
 * Marketing > Referrals: "Referred by" — type a name, code or phone and pick
 * the student who brought this person. v-model is the student id ('' = none).
 */
const props = defineProps<{
  modelValue: string
  /** The student already picked, to show their name. */
  selected?: ReferralStudent | null
  /** Never offer this student (the one being edited). */
  exclude?: number | null
  label?: string
  hint?: string
  error?: string
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { t } = useI18n()
const id = useId()

const picked = ref<ReferralStudent | null>(props.selected ?? null)
watch(
  () => props.selected,
  (value) => {
    if (value) picked.value = value
  },
)
watch(
  () => props.modelValue,
  (value) => {
    if (!value) picked.value = null
  },
)

const term = ref('')
const options = ref<ReferralStudent[]>([])
const open = ref(false)
const loading = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

function search() {
  clearTimeout(timer)
  open.value = true
  timer = setTimeout(async () => {
    loading.value = true
    options.value = await referralsService.students(term.value.trim(), props.exclude).catch(() => [])
    loading.value = false
  }, 250)
}

function choose(student: ReferralStudent) {
  picked.value = student
  emit('update:modelValue', String(student.id))
  open.value = false
  term.value = ''
}

function clear() {
  picked.value = null
  emit('update:modelValue', '')
}

function close() {
  setTimeout(() => (open.value = false), 150)
}

onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div class="relative">
    <label :for="id" class="mb-1.5 block text-sm font-medium text-neutral-700">{{ label ?? t('marketing.referrals.referredBy') }}</label>
    <div v-if="modelValue && picked" class="flex items-center justify-between gap-2 rounded-lg border border-neutral-300 bg-neutral-50 px-3 py-2 text-sm">
      <span class="truncate text-neutral-900">{{ picked.name }}<span v-if="picked.student_code" class="ml-1 text-xs text-neutral-500">({{ picked.student_code }})</span></span>
      <button type="button" class="shrink-0 text-xs font-medium text-danger-600 hover:text-red-700" @click="clear">{{ t('marketing.referrals.clear') }}</button>
    </div>
    <input
      v-else
      :id="id"
      v-model="term"
      type="search"
      autocomplete="off"
      :placeholder="t('marketing.referrals.searchStudent')"
      class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
      @focus="search"
      @input="search"
      @blur="close"
    />
    <ul v-if="open && !modelValue" class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-neutral-200 bg-white py-1 text-sm shadow-lg">
      <li v-if="loading" class="px-3 py-2 text-neutral-500">{{ t('marketing.referrals.searching') }}</li>
      <li v-else-if="options.length === 0" class="px-3 py-2 text-neutral-500">{{ t('marketing.referrals.noStudent') }}</li>
      <li v-for="student in options" v-else :key="student.id">
        <button type="button" class="block w-full px-3 py-2 text-left hover:bg-primary-50" @mousedown.prevent="choose(student)">
          {{ student.name }} <span v-if="student.student_code" class="text-xs text-neutral-500">({{ student.student_code }})</span>
        </button>
      </li>
    </ul>
    <p v-if="error" class="mt-1.5 text-sm text-danger-600">{{ error }}</p>
    <p v-else-if="hint" class="mt-1.5 text-xs text-neutral-500">{{ hint }}</p>
  </div>
</template>

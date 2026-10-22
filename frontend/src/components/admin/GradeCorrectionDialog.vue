<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { resultApprovalsService, type GradeSheet, type SheetColumn, type SheetStudent, type SheetTarget } from '@/services/gradebook'
import { ApiRequestError } from '@/types/api'

/**
 * Grade Correction (docs/academics/gradebook.md, 7c): ask to change one score
 * of approved results, with a reason. It is decided in eApprovals; nothing
 * changes until it is approved.
 */
const props = defineProps<{ sheet: GradeSheet; target: SheetTarget; student: SheetStudent | null; column: SheetColumn | null }>()
const open = defineModel<boolean>({ required: true })
const emit = defineEmits<{ sent: [] }>()

const { t, locale } = useI18n()
const value = ref('')
const reason = ref('')
const busy = ref(false)
const error = ref<ApiRequestError | null>(null)

const isScore = computed(() => props.sheet.scheme?.mode === 'score')
const pickName = (en: string | null | undefined, kh: string | null | undefined) => ((locale.value === 'km' ? kh || en : en || kh) ?? '').trim()
const current = computed(() => (props.student && props.column ? props.student.cells[props.column.key] : undefined))
const currentText = computed(() => {
  const c = current.value
  if (!c) return '—'
  if (c.absent) return t('gradebook.corrections.absent')
  if (!isScore.value) {
    const level = props.sheet.scheme?.levels.find((l) => l.id === c.level_id)
    return level ? `${level.code} · ${pickName(level.label, level.label_km)}` : '—'
  }
  return c.score === null ? '—' : String(c.score)
})
const label = computed(() => {
  const c = props.column
  if (!c) return ''
  const name = pickName(c.name, c.name_km)
  return c.occurrences && c.occurrences > 1 ? `${name} ${c.occurrence}` : name
})

watch(open, (isOpen) => {
  if (!isOpen) return
  value.value = ''
  reason.value = ''
  error.value = null
})

async function send() {
  if (!props.student || !props.column) return
  busy.value = true
  error.value = null
  const raw = value.value.trim()
  const absent = ['a', 'ab', 'abs', 'អ'].includes(raw.toLowerCase())
  try {
    await resultApprovalsService.requestCorrection({
      ...props.target,
      student_id: props.student.id,
      ...(isScore.value
        ? { component_id: props.column.component_id, occurrence: props.column.occurrence, absent, score: absent || raw === '' ? null : Number(raw.replace(',', '.')) }
        : { criterion_id: props.column.criterion_id, level_id: raw ? Number(raw) : null }),
      reason: reason.value.trim(),
    })
    open.value = false
    emit('sent')
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('gradebook.saveFailed'), 0)
  } finally {
    busy.value = false
  }
}

const generalError = computed(() => {
  const e = error.value
  if (!e) return null
  return e.fieldError('score') ?? e.fieldError('level_id') ?? e.fieldError('sheet') ?? e.fieldError('reason') ?? e.message
})
</script>

<template>
  <BaseModal v-model="open" :title="t('gradebook.corrections.requestTitle')" size="sm">
    <form v-if="student && column" id="grade-correction-form" class="space-y-4" @submit.prevent="send">
      <p class="text-sm text-neutral-600">{{ t('gradebook.corrections.requestHint') }}</p>
      <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
        <dt class="text-neutral-500">{{ t('gradebook.entry.student') }}</dt>
        <dd class="font-medium text-neutral-900">{{ student.name }}</dd>
        <dt class="text-neutral-500">{{ t('gradebook.corrections.what') }}</dt>
        <dd class="font-medium text-neutral-900">{{ label }}<span v-if="isScore && column.max_score"> /{{ column.max_score }}</span></dd>
        <dt class="text-neutral-500">{{ t('gradebook.corrections.now') }}</dt>
        <dd class="font-medium text-neutral-900">{{ currentText }}</dd>
      </dl>
      <div>
        <label class="mb-1 block text-sm font-medium text-neutral-700">{{ t('gradebook.corrections.newValue') }}</label>
        <input
          v-if="isScore"
          v-model="value"
          inputmode="decimal"
          required
          class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm"
          :placeholder="t('gradebook.corrections.newValueHint')"
        />
        <select v-else v-model="value" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm">
          <option value="">—</option>
          <option v-for="l in sheet.scheme?.levels ?? []" :key="l.id" :value="String(l.id)">{{ l.code }} · {{ pickName(l.label, l.label_km) }}</option>
        </select>
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium text-neutral-700">{{ t('gradebook.corrections.reason') }} <span class="text-red-500">*</span></label>
        <textarea v-model="reason" rows="3" required minlength="3" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm" :placeholder="t('gradebook.corrections.reasonHint')" />
      </div>
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
    </form>
    <template #footer>
      <BaseButton variant="ghost" @click="open = false">{{ t('gradebook.cancel') }}</BaseButton>
      <BaseButton type="submit" form="grade-correction-form" :loading="busy">{{ t('gradebook.corrections.send') }}</BaseButton>
    </template>
  </BaseModal>
</template>

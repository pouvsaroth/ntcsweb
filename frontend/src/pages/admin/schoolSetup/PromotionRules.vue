<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { gradeLevelsService, promotionsService, type GradeLevel, type PromotionRule } from '@/services/schoolSetup'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * School setup > Promotion rules: what a student needs to go up a grade —
 * a minimum average, a minimum attendance, at most so many failed subjects.
 * One default rule for every grade, and a grade's own rule where it differs.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('promotion-rules.manage'))

const rules = ref<PromotionRule[]>([])
const grades = ref<GradeLevel[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    rules.value = await promotionsService.rules()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.loadFailed')
  } finally {
    loading.value = false
  }
}

const hasDefault = computed(() => rules.value.some((r) => r.grade_level_id === null))
const who = (r: PromotionRule) => r.grade_level ?? t('schoolSetup.rules.allGrades')
const pct = (n: number | null) => (n === null ? t('schoolSetup.rules.notChecked') : `≥ ${n}%`)
const failed = (n: number | null) => (n === null ? t('schoolSetup.rules.notChecked') : t('schoolSetup.rules.atMost', { count: n }))

const columns = computed(() => [
  { key: 'who', label: t('schoolSetup.rules.appliesTo') },
  { key: 'average', label: t('schoolSetup.rules.minAverage') },
  { key: 'attendance', label: t('schoolSetup.rules.minAttendance') },
  { key: 'failed', label: t('schoolSetup.rules.maxFailed') },
  { key: 'pass', label: t('schoolSetup.rules.passMark') },
  ...(canManage.value ? [{ key: 'actions', label: t('schoolSetup.actions'), align: 'text-right' }] : []),
])

// --- Form ----------------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<PromotionRule | null>(null)
const form = reactive({ grade_level_id: '', min_average: '', min_attendance: '', max_failed_subjects: '', pass_mark: '50', notes: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const gradeOptions = computed(() => {
  const taken = new Set(rules.value.filter((r) => r.id !== editing.value?.id).map((r) => r.grade_level_id ?? 0))
  return [
    ...(!taken.has(0) ? [{ value: '', label: t('schoolSetup.rules.allGrades') }] : []),
    ...grades.value.filter((g) => !taken.has(g.id)).map((g) => ({ value: String(g.id), label: g.name })),
  ]
})

function open(rule: PromotionRule | null) {
  editing.value = rule
  const num = (n: number | null) => (n === null ? '' : String(n))
  form.grade_level_id = rule ? (rule.grade_level_id === null ? '' : String(rule.grade_level_id)) : hasDefault.value ? String(gradeOptions.value[0]?.value ?? '') : ''
  form.min_average = num(rule?.min_average ?? (rule ? null : 50))
  form.min_attendance = num(rule?.min_attendance ?? (rule ? null : 80))
  form.max_failed_subjects = num(rule?.max_failed_subjects ?? null)
  form.pass_mark = String(rule?.pass_mark ?? 50)
  form.notes = rule?.notes ?? ''
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const num = (s: string) => (s === '' ? null : Number(s))
  try {
    await promotionsService.saveRule({
      grade_level_id: form.grade_level_id ? Number(form.grade_level_id) : null,
      min_average: num(form.min_average),
      min_attendance: num(form.min_attendance),
      max_failed_subjects: num(form.max_failed_subjects),
      pass_mark: Number(form.pass_mark) || 0,
      notes: form.notes.trim() || null,
    })
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(rule: PromotionRule) {
  if (!(await confirmDialog.confirm({ message: t('schoolSetup.rules.deleteConfirm', { name: who(rule) }), danger: true }))) return
  try {
    await promotionsService.removeRule(rule.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.deleteFailed')
  }
}

onMounted(async () => {
  void load()
  grades.value = await gradeLevelsService.list(true).catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <p class="max-w-2xl text-sm text-neutral-500">{{ t('schoolSetup.rules.hint') }}</p>
      <BaseButton v-if="canManage" class="ml-auto" :disabled="gradeOptions.length === 0" @click="open(null)">{{ t('schoolSetup.rules.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="!loading && !hasDefault" variant="info" class="mb-4">{{ t('schoolSetup.rules.noDefault') }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <div v-else class="space-y-2">
        <div v-for="row in rules" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <p class="text-sm font-semibold text-neutral-800">{{ who(row) }}</p>
          <dl class="mt-1 grid grid-cols-2 gap-x-3 gap-y-0.5 text-xs">
            <dt class="text-neutral-500">{{ t('schoolSetup.rules.minAverage') }}</dt><dd class="text-neutral-800">{{ pct(row.min_average) }}</dd>
            <dt class="text-neutral-500">{{ t('schoolSetup.rules.minAttendance') }}</dt><dd class="text-neutral-800">{{ pct(row.min_attendance) }}</dd>
            <dt class="text-neutral-500">{{ t('schoolSetup.rules.maxFailed') }}</dt><dd class="text-neutral-800">{{ failed(row.max_failed_subjects) }}</dd>
            <dt class="text-neutral-500">{{ t('schoolSetup.rules.passMark') }}</dt><dd class="text-neutral-800">{{ row.pass_mark }}%</dd>
          </dl>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="open(row)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('schoolSetup.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="rules" row-key="id" :loading="loading" :empty-message="t('schoolSetup.rules.empty')">
        <template #cell-who="{ row }">
          <span class="font-medium text-neutral-800">{{ who(row as PromotionRule) }}</span>
          <p v-if="row.notes" class="text-xs text-neutral-500">{{ row.notes }}</p>
        </template>
        <template #cell-average="{ row }">{{ pct(row.min_average) }}</template>
        <template #cell-attendance="{ row }">{{ pct(row.min_attendance) }}</template>
        <template #cell-failed="{ row }">{{ failed(row.max_failed_subjects) }}</template>
        <template #cell-pass="{ row }">{{ row.pass_mark }}%</template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="open(row as PromotionRule)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as PromotionRule)">{{ t('schoolSetup.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BaseModal v-model="formOpen" :title="editing ? t('schoolSetup.rules.edit') : t('schoolSetup.rules.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <BaseSelect v-model="form.grade_level_id" :options="gradeOptions" :disabled="editing !== null" :label="t('schoolSetup.rules.appliesTo')" :error="errors.grade_level_id?.[0]" />
        <p class="text-xs text-neutral-500">{{ t('schoolSetup.rules.emptyMeansSkip') }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.min_average" type="number" :label="t('schoolSetup.rules.minAverage') + ' (%)'" :error="errors.min_average?.[0]" />
          <BaseInput v-model="form.min_attendance" type="number" :label="t('schoolSetup.rules.minAttendance') + ' (%)'" :error="errors.min_attendance?.[0]" />
          <BaseInput v-model="form.max_failed_subjects" type="number" :label="t('schoolSetup.rules.maxFailed')" :error="errors.max_failed_subjects?.[0]" />
          <BaseInput v-model="form.pass_mark" type="number" required :label="t('schoolSetup.rules.passMark') + ' (%)'" :hint="t('schoolSetup.rules.passMarkHint')" :error="errors.pass_mark?.[0]" />
        </div>
        <BaseInput v-model="form.notes" :label="t('schoolSetup.rules.notes')" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

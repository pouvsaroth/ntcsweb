<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { GRADE_STAGES, gradeLevelsService, type GradeLevel, type GradeStage } from '@/services/schoolSetup'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * School setup > Grade levels: Grade 1–12 (or any level the school adds), in
 * order and by education stage. The order is what "the next grade" means
 * when promoting students.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('grade-levels.manage'))

const levels = ref<GradeLevel[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const seeding = ref(false)

async function load() {
  loading.value = true
  error.value = null
  try {
    levels.value = await gradeLevelsService.list()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.loadFailed')
  } finally {
    loading.value = false
  }
}

const stageLabel = (stage: GradeStage | null) => (stage ? t(`schoolSetup.stages.${stage}`) : '—')
const stageOptions = computed(() => [{ value: '', label: '—' }, ...GRADE_STAGES.map((s) => ({ value: s, label: t(`schoolSetup.stages.${s}`) }))])

/** Grouped by stage, in order, for the phone cards. */
const columns = computed(() => [
  { key: 'order', label: t('schoolSetup.order') },
  { key: 'code', label: t('schoolSetup.grades.code') },
  { key: 'name', label: t('schoolSetup.grades.name') },
  { key: 'stage', label: t('schoolSetup.grades.stage') },
  { key: 'classes', label: t('schoolSetup.grades.classes') },
  ...(canManage.value ? [{ key: 'actions', label: t('schoolSetup.actions'), align: 'text-right' }] : []),
])

async function seedDefaults() {
  seeding.value = true
  error.value = null
  try {
    const { added } = await gradeLevelsService.seedDefaults()
    notice.value = t('schoolSetup.grades.seeded', { count: added })
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.saveFailed')
  } finally {
    seeding.value = false
  }
}

// --- Form ----------------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<GradeLevel | null>(null)
const form = reactive({ code: '', name: '', stage: '', level_order: '1', is_active: true })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

function open(level: GradeLevel | null) {
  editing.value = level
  const nextOrder = levels.value.reduce((max, l) => Math.max(max, l.level_order), 0) + 1
  form.code = level?.code ?? ''
  form.name = level?.name ?? ''
  form.stage = level?.stage ?? ''
  form.level_order = String(level?.level_order ?? nextOrder)
  form.is_active = level?.is_active ?? true
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    code: form.code.trim(),
    name: form.name.trim(),
    stage: (form.stage || null) as GradeStage | null,
    level_order: Number(form.level_order) || 0,
    is_active: form.is_active,
  }
  try {
    if (editing.value) await gradeLevelsService.update(editing.value.id, input)
    else await gradeLevelsService.create(input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(level: GradeLevel) {
  if (!(await confirmDialog.confirm({ message: t('schoolSetup.grades.deleteConfirm', { name: level.name }), danger: true }))) return
  error.value = null
  try {
    await gradeLevelsService.remove(level.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.errors ? Object.values(e.errors)[0]?.[0] ?? e.message : e.message) : t('schoolSetup.deleteFailed')
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <p class="text-sm text-neutral-500">{{ t('schoolSetup.grades.hint') }}</p>
      <div v-if="canManage" class="ml-auto flex flex-wrap gap-2">
        <BaseButton variant="outline" :loading="seeding" @click="seedDefaults">{{ t('schoolSetup.grades.seed') }}</BaseButton>
        <BaseButton @click="open(null)">{{ t('schoolSetup.grades.add') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="success" class="mb-4">{{ notice }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="levels.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('schoolSetup.grades.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in levels" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]" :class="{ 'opacity-60': !row.is_active }">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ row.name }} <span class="font-mono text-xs font-normal text-neutral-500">{{ row.code }}</span></p>
            <BaseBadge v-if="!row.is_active" variant="neutral">{{ t('schoolSetup.inactive') }}</BaseBadge>
          </div>
          <p class="text-xs text-neutral-500">{{ stageLabel(row.stage) }} · {{ t('schoolSetup.grades.classesN', { count: row.classes_count }) }}</p>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="open(row)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('schoolSetup.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="levels" row-key="id" :loading="loading" :empty-message="t('schoolSetup.grades.empty')">
        <template #cell-order="{ row }">{{ row.level_order }}</template>
        <template #cell-code="{ row }"><span class="font-mono text-sm">{{ row.code }}</span></template>
        <template #cell-name="{ row }">
          <span class="font-medium text-neutral-800">{{ row.name }}</span>
          <BaseBadge v-if="!row.is_active" variant="neutral" class="ml-2">{{ t('schoolSetup.inactive') }}</BaseBadge>
        </template>
        <template #cell-stage="{ row }">{{ stageLabel(row.stage) }}</template>
        <template #cell-classes="{ row }">{{ row.classes_count }}</template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="open(row as GradeLevel)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as GradeLevel)">{{ t('schoolSetup.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BaseModal v-model="formOpen" :title="editing ? t('schoolSetup.grades.edit') : t('schoolSetup.grades.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.code" required :label="t('schoolSetup.grades.code')" :hint="t('schoolSetup.grades.codeHint')" :error="errors.code?.[0]" />
          <BaseInput v-model="form.name" required :label="t('schoolSetup.grades.name')" :error="errors.name?.[0]" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.stage" :options="stageOptions" :label="t('schoolSetup.grades.stage')" :error="errors.stage?.[0]" />
          <BaseInput v-model="form.level_order" type="number" required :label="t('schoolSetup.order')" :hint="t('schoolSetup.grades.orderHint')" :error="errors.level_order?.[0]" />
        </div>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('schoolSetup.active') }}
        </label>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

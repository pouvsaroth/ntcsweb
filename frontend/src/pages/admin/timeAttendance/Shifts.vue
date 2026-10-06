<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatMinutes, shiftsService, type Shift } from '@/services/timeAttendance'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Attendance & Time > Shift — working hours, and how late or early
 * still counts as on time. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canManage = computed(() => auth.can('staff-attendance.manage'))

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<Shift>((query) => shiftsService.list(query))

const columns = computed(() => [
  { key: 'name', label: t('admin.timeAttendance.shifts.name') },
  { key: 'hours', label: t('admin.timeAttendance.shifts.hours') },
  { key: 'break', label: t('admin.timeAttendance.shifts.break') },
  { key: 'grace', label: t('admin.timeAttendance.shifts.grace') },
  { key: 'is_active', label: t('admin.organization.status') },
  ...(canManage.value ? [{ key: 'actions', label: t('admin.organization.actions'), align: 'text-right' }] : []),
])

// --- Form ----------------------------------------------------------------------

const formOpen = ref(false)
const editing = ref<Shift | null>(null)
const form = reactive({
  code: '',
  name: '',
  start_time: '08:00',
  end_time: '17:00',
  break_minutes: '60',
  late_grace_minutes: '10',
  early_leave_grace_minutes: '0',
  color: '#3b82f6',
  is_active: true,
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const actionError = ref<string | null>(null)

function open(shift: Shift | null) {
  editing.value = shift
  form.code = shift?.code ?? ''
  form.name = shift?.name ?? ''
  form.start_time = shift?.start_time ?? '08:00'
  form.end_time = shift?.end_time ?? '17:00'
  form.break_minutes = String(shift?.break_minutes ?? 60)
  form.late_grace_minutes = String(shift?.late_grace_minutes ?? 10)
  form.early_leave_grace_minutes = String(shift?.early_leave_grace_minutes ?? 0)
  form.color = shift?.color ?? '#3b82f6'
  form.is_active = shift?.is_active ?? true
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    ...form,
    break_minutes: Number(form.break_minutes || 0),
    late_grace_minutes: Number(form.late_grace_minutes || 0),
    early_leave_grace_minutes: Number(form.early_leave_grace_minutes || 0),
  }
  try {
    if (editing.value) await shiftsService.update(editing.value.id, input)
    else await shiftsService.create(input)
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(shift: Shift) {
  if (!(await confirmDialog.confirm({ message: t('admin.timeAttendance.shifts.deleteConfirm', { name: shift.name }), danger: true }))) return
  actionError.value = null
  try {
    await shiftsService.remove(shift.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-4 flex items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">{{ t('admin.timeAttendance.shifts.hint') }}</p>
      <BaseButton v-if="canManage" @click="open(null)">{{ t('admin.timeAttendance.shifts.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.timeAttendance.shifts.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="flex items-center gap-2 text-sm font-semibold text-neutral-800">
              <span class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: row.color ?? '#9ca3af' }" />
              {{ row.name }} <span class="font-normal text-neutral-500">({{ row.code }})</span>
            </p>
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm text-neutral-700">
            {{ row.start_time }} – {{ row.end_time }}<template v-if="row.overnight"> (+1)</template> · {{ formatMinutes(row.work_minutes) }}
          </p>
          <p class="text-xs text-neutral-500">{{ t('admin.timeAttendance.shifts.graceLine', { late: row.late_grace_minutes, early: row.early_leave_grace_minutes }) }}</p>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="open(row)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.organization.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.timeAttendance.shifts.empty')">
        <template #cell-name="{ row }">
          <p class="flex items-center gap-2 font-medium text-neutral-800">
            <span class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: row.color ?? '#9ca3af' }" />
            {{ row.name }}
          </p>
          <p class="text-xs text-neutral-500">{{ row.code }}</p>
        </template>
        <template #cell-hours="{ row }">
          {{ row.start_time }} – {{ row.end_time }}<span v-if="row.overnight" class="text-xs text-neutral-500"> (+1)</span>
          <p class="text-xs text-neutral-500">{{ formatMinutes(row.work_minutes) }}</p>
        </template>
        <template #cell-break="{ row }">{{ row.break_minutes ? formatMinutes(row.break_minutes) : '—' }}</template>
        <template #cell-grace="{ row }">{{ t('admin.timeAttendance.shifts.graceLine', { late: row.late_grace_minutes, early: row.early_leave_grace_minutes }) }}</template>
        <template #cell-is_active="{ row }">
          <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="open(row as Shift)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as Shift)">{{ t('admin.organization.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal v-model="formOpen" :title="editing ? t('admin.timeAttendance.shifts.edit') : t('admin.timeAttendance.shifts.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.code" required :label="t('admin.organization.code')" :error="errors.code?.[0]" />
          <BaseInput v-model="form.name" required :label="t('admin.timeAttendance.shifts.name')" :error="errors.name?.[0]" />
          <BaseInput v-model="form.start_time" type="time" required :label="t('admin.timeAttendance.shifts.start')" :error="errors.start_time?.[0]" />
          <BaseInput v-model="form.end_time" type="time" required :label="t('admin.timeAttendance.shifts.end')" :hint="t('admin.timeAttendance.shifts.endHint')" :error="errors.end_time?.[0]" />
          <BaseInput v-model="form.break_minutes" type="number" min="0" :label="t('admin.timeAttendance.shifts.breakMinutes')" :error="errors.break_minutes?.[0]" />
          <div>
            <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.timeAttendance.shifts.color') }}</label>
            <input v-model="form.color" type="color" class="h-[38px] w-full cursor-pointer rounded-lg border border-neutral-300 p-1" />
          </div>
          <BaseInput v-model="form.late_grace_minutes" type="number" min="0" :label="t('admin.timeAttendance.shifts.lateGrace')" :hint="t('admin.timeAttendance.shifts.lateGraceHint')" :error="errors.late_grace_minutes?.[0]" />
          <BaseInput v-model="form.early_leave_grace_minutes" type="number" min="0" :label="t('admin.timeAttendance.shifts.earlyGrace')" :error="errors.early_leave_grace_minutes?.[0]" />
        </div>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.organization.statusActive') }}
        </label>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

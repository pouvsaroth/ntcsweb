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
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { holidaysService, type Holiday } from '@/services/timeAttendance'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Attendance & Time > Holidays — days off for everyone, by year. Nobody
 * is marked absent on these. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canManage = computed(() => auth.can('staff-attendance.manage'))

const thisYear = new Date().getFullYear()
const year = ref(thisYear)
const yearOptions = [thisYear - 1, thisYear, thisYear + 1, thisYear + 2].map((y) => ({ value: String(y), label: String(y) }))

const { items, loading, error, fetch } = usePaginatedResource<Holiday>((query) => holidaysService.list({ ...query, per_page: 100 }, year.value))

const totalDays = computed(() => items.value.reduce((sum, h) => sum + h.days, 0))

function onYear(value: string) {
  year.value = Number(value)
  void fetch()
}

function range(h: Holiday): string {
  return h.start_date === h.end_date ? formatDate(h.start_date) : `${formatDate(h.start_date)} – ${formatDate(h.end_date)}`
}

const columns = computed(() => [
  { key: 'date', label: t('admin.timeAttendance.holidays.date') },
  { key: 'name', label: t('admin.timeAttendance.holidays.name') },
  { key: 'days', label: t('admin.timeAttendance.holidays.days') },
  ...(canManage.value ? [{ key: 'actions', label: t('admin.organization.actions'), align: 'text-right' }] : []),
])

// --- Form ----------------------------------------------------------------------

const formOpen = ref(false)
const editing = ref<Holiday | null>(null)
const form = reactive({ name: '', start_date: '', end_date: '', description: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const actionError = ref<string | null>(null)

function open(holiday: Holiday | null) {
  editing.value = holiday
  form.name = holiday?.name ?? ''
  form.start_date = holiday?.start_date ?? ''
  form.end_date = holiday && holiday.end_date !== holiday.start_date ? holiday.end_date : ''
  form.description = holiday?.description ?? ''
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = { name: form.name, start_date: form.start_date, end_date: form.end_date || null, description: form.description.trim() || null }
  try {
    if (editing.value) await holidaysService.update(editing.value.id, input)
    else await holidaysService.create(input)
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(holiday: Holiday) {
  if (!(await confirmDialog.confirm({ message: t('admin.timeAttendance.holidays.deleteConfirm', { name: holiday.name }), danger: true }))) return
  actionError.value = null
  try {
    await holidaysService.remove(holiday.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-32" :model-value="String(year)" :options="yearOptions" @update:model-value="onYear" />
      <p class="text-sm text-neutral-500">{{ t('admin.timeAttendance.holidays.total', { count: totalDays }) }}</p>
      <BaseButton v-if="canManage" class="ml-auto" @click="open(null)">{{ t('admin.timeAttendance.holidays.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.timeAttendance.holidays.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <p class="text-sm font-semibold text-neutral-800">{{ row.name }}</p>
          <p class="text-xs text-neutral-500">{{ range(row) }} · {{ t('admin.timeAttendance.holidays.daysN', { count: row.days }) }}</p>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="open(row)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.organization.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.timeAttendance.holidays.empty')">
        <template #cell-date="{ row }">{{ range(row as Holiday) }}</template>
        <template #cell-name="{ row }">
          <p class="font-medium text-neutral-800">{{ row.name }}</p>
          <p v-if="row.description" class="text-xs text-neutral-500">{{ row.description }}</p>
        </template>
        <template #cell-days="{ row }">{{ row.days }}</template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="open(row as Holiday)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as Holiday)">{{ t('admin.organization.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BaseModal v-model="formOpen" :title="editing ? t('admin.timeAttendance.holidays.edit') : t('admin.timeAttendance.holidays.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <BaseInput v-model="form.name" required :label="t('admin.timeAttendance.holidays.name')" :error="errors.name?.[0]" />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.start_date" type="date" required :label="t('admin.timeAttendance.holidays.from')" :error="errors.start_date?.[0]" />
          <BaseInput v-model="form.end_date" type="date" :label="t('admin.timeAttendance.holidays.to')" :hint="t('admin.timeAttendance.holidays.toHint')" :error="errors.end_date?.[0]" />
        </div>
        <BaseInput v-model="form.description" :label="t('admin.organization.description')" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { departmentsService, type Department } from '@/services/departments'
import { attendanceSignOffService, lastMonth, type SignOffRow } from '@/services/staffAttendance'
import { formatMinutes } from '@/services/timeAttendance'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Attendance & Time > Attendance approval: a month's totals per
 * person, and the manager's sign-off — which locks that month (no more
 * check-ins, edits, imports or corrections) until unlocked. Cards on a
 * phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canSignOff = computed(() => auth.can('staff-attendance.approve'))

const month = ref(lastMonth())
const departmentId = ref('')
const rows = ref<SignOffRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const busy = ref(false)
const selected = ref<number[]>([])

const departments = ref<Department[]>([])
const departmentOptions = computed(() => [
  { value: '', label: t('admin.timeAttendance.allDepartments') },
  ...departments.value.map((d) => ({ value: String(d.id), label: d.name })),
])

const open = computed(() => rows.value.filter((r) => r.approval === null))
const allOpenSelected = computed(() => open.value.length > 0 && open.value.every((r) => selected.value.includes(r.staff.id)))

async function load() {
  loading.value = true
  error.value = null
  selected.value = []
  try {
    const result = await attendanceSignOffService.list({ month: month.value, ...(departmentId.value ? { department_id: Number(departmentId.value) } : {}) })
    rows.value = result.staff
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.loadFailed')
  } finally {
    loading.value = false
  }
}

function toggle(id: number) {
  selected.value = selected.value.includes(id) ? selected.value.filter((s) => s !== id) : [...selected.value, id]
}

function toggleAll() {
  selected.value = allOpenSelected.value ? [] : open.value.map((r) => r.staff.id)
}

async function signOff(ids: number[]) {
  const incomplete = rows.value.filter((r) => ids.includes(r.staff.id) && r.incomplete > 0).length
  const message = incomplete > 0
    ? t('admin.timeAttendance.signOff.confirmIncomplete', { count: ids.length, incomplete })
    : t('admin.timeAttendance.signOff.confirm', { count: ids.length, month: month.value })
  if (!(await confirmDialog.confirm({ message }))) return

  busy.value = true
  notice.value = null
  error.value = null
  try {
    const result = await attendanceSignOffService.signOff({ month: month.value, staff_ids: ids, note: null })
    notice.value = t('admin.timeAttendance.signOff.done', { count: result.signed_off })
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.errors?.month?.[0] ?? e.message) : t('admin.timeAttendance.saveFailed')
  } finally {
    busy.value = false
  }
}

async function unlock(row: SignOffRow) {
  if (!row.approval || !(await confirmDialog.confirm({ message: t('admin.timeAttendance.signOff.unlockConfirm', { name: row.staff.name }), danger: true }))) return
  busy.value = true
  try {
    await attendanceSignOffService.unlock(row.approval.id)
    await load()
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  void load()
  departments.value = await departmentsService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseInput v-model="month" type="month" class="w-40" @update:model-value="load" />
      <BaseSelect class="w-48" :model-value="departmentId" :options="departmentOptions" @update:model-value="(v: string) => { departmentId = v; load() }" />
      <div v-if="canSignOff" class="ml-auto flex gap-2">
        <BaseButton :disabled="selected.length === 0" :loading="busy" @click="signOff(selected)">
          {{ t('admin.timeAttendance.signOff.selected', { count: selected.length }) }}
        </BaseButton>
      </div>
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.timeAttendance.signOff.hint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="success" class="mb-4">{{ notice }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('admin.timeAttendance.noStaff') }}
    </p>

    <template v-else>
      <!-- Phone -->
      <div class="space-y-2 sm:hidden">
        <div v-for="row in rows" :key="row.staff.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <label class="flex min-w-0 items-start gap-2">
              <input
                v-if="canSignOff && !row.approval"
                type="checkbox"
                class="mt-1 rounded border-neutral-300 text-primary-600"
                :checked="selected.includes(row.staff.id)"
                @change="toggle(row.staff.id)"
              />
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-neutral-800">{{ row.staff.name }}</span>
                <span class="text-xs text-neutral-500">{{ row.staff.employee_code }}</span>
              </span>
            </label>
            <BaseBadge :variant="row.approval ? 'success' : 'neutral'">{{ row.approval ? t('admin.timeAttendance.signOff.signedOff') : t('admin.timeAttendance.signOff.open') }}</BaseBadge>
          </div>
          <p class="mt-2 text-xs text-neutral-600">
            ✓ {{ row.totals.present + row.totals.late + row.totals.early_leave }} · L {{ row.totals.late }} · A {{ row.totals.absent }} · LV {{ row.totals.leave }} ·
            {{ formatMinutes(row.totals.worked_minutes) }}
          </p>
          <p v-if="row.incomplete" class="text-xs text-amber-700">{{ t('admin.timeAttendance.signOff.incomplete', { count: row.incomplete }) }}</p>
          <p v-if="row.approval" class="text-xs text-neutral-500">{{ t('admin.timeAttendance.signOff.by', { name: row.approval.approved_by ?? '—', date: formatDate(row.approval.approved_at) }) }}</p>
          <div v-if="canSignOff" class="mt-2 flex justify-end">
            <button v-if="row.approval" type="button" class="text-sm font-medium text-danger-600" @click="unlock(row)">{{ t('admin.timeAttendance.signOff.unlock') }}</button>
            <BaseButton v-else size="sm" @click="signOff([row.staff.id])">{{ t('admin.timeAttendance.signOff.signOne') }}</BaseButton>
          </div>
        </div>
      </div>

      <!-- Computer -->
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="min-w-full text-sm">
          <thead class="bg-neutral-50 text-left text-xs font-semibold text-neutral-600">
            <tr>
              <th v-if="canSignOff" class="w-10 px-3 py-2"><input type="checkbox" class="rounded border-neutral-300 text-primary-600" :checked="allOpenSelected" @change="toggleAll" /></th>
              <th class="px-3 py-2">{{ t('admin.timeAttendance.entry.staff') }}</th>
              <th class="px-2 py-2 text-center">{{ t('admin.timeAttendance.statuses.present') }}</th>
              <th class="px-2 py-2 text-center">{{ t('admin.timeAttendance.statuses.late') }}</th>
              <th class="px-2 py-2 text-center">{{ t('admin.timeAttendance.statuses.early_leave') }}</th>
              <th class="px-2 py-2 text-center">{{ t('admin.timeAttendance.statuses.absent') }}</th>
              <th class="px-2 py-2 text-center">{{ t('admin.timeAttendance.statuses.leave') }}</th>
              <th class="px-2 py-2 text-right">{{ t('admin.timeAttendance.worked') }}</th>
              <th class="px-2 py-2 text-right">{{ t('admin.timeAttendance.tabs.overtime') }}</th>
              <th class="px-3 py-2">{{ t('admin.organization.status') }}</th>
              <th v-if="canSignOff" class="px-3 py-2" />
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in rows" :key="row.staff.id">
              <td v-if="canSignOff" class="px-3 py-2">
                <input v-if="!row.approval" type="checkbox" class="rounded border-neutral-300 text-primary-600" :checked="selected.includes(row.staff.id)" @change="toggle(row.staff.id)" />
              </td>
              <td class="px-3 py-2">
                <p class="font-medium text-neutral-800">{{ row.staff.name }}</p>
                <p class="text-xs text-neutral-500">{{ row.staff.employee_code }}</p>
                <p v-if="row.incomplete" class="text-xs text-amber-700">{{ t('admin.timeAttendance.signOff.incomplete', { count: row.incomplete }) }}</p>
              </td>
              <td class="px-2 text-center">{{ row.totals.present + row.totals.late + row.totals.early_leave }}</td>
              <td class="px-2 text-center">{{ row.totals.late }}</td>
              <td class="px-2 text-center">{{ row.totals.early_leave }}</td>
              <td class="px-2 text-center" :class="row.totals.absent ? 'font-semibold text-red-700' : ''">{{ row.totals.absent }}</td>
              <td class="px-2 text-center">{{ row.totals.leave }}</td>
              <td class="whitespace-nowrap px-2 text-right">{{ formatMinutes(row.totals.worked_minutes) }}</td>
              <td class="whitespace-nowrap px-2 text-right">{{ row.totals.overtime_minutes ? formatMinutes(row.totals.overtime_minutes) : '—' }}</td>
              <td class="px-3 py-2">
                <BaseBadge :variant="row.approval ? 'success' : 'neutral'">{{ row.approval ? t('admin.timeAttendance.signOff.signedOff') : t('admin.timeAttendance.signOff.open') }}</BaseBadge>
                <p v-if="row.approval" class="text-xs text-neutral-500">{{ t('admin.timeAttendance.signOff.by', { name: row.approval.approved_by ?? '—', date: formatDate(row.approval.approved_at) }) }}</p>
              </td>
              <td v-if="canSignOff" class="px-3 py-2 text-right">
                <button v-if="row.approval" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="unlock(row)">{{ t('admin.timeAttendance.signOff.unlock') }}</button>
                <button v-else type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="signOff([row.staff.id])">{{ t('admin.timeAttendance.signOff.signOne') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

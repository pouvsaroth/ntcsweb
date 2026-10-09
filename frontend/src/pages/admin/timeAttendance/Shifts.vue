<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatMinutes, shiftsService, type Shift, type ShiftDay } from '@/services/timeAttendance'
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

// Day / From / To rows, like Add Class's weekly schedule — ISO day numbers, 1 = Monday … 7 = Sunday.
const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const

const dayOptions = computed(() => WEEKDAYS.map((day, i) => ({ value: String(i + 1), label: t(`admin.classes.${day}`) })))

function dayLabel(dayOfWeek: number): string {
  return t(`admin.timeAttendance.weekdays.${WEEKDAYS[dayOfWeek - 1]}`)
}

/** The list's Hours text: each day's own hours, or the shift's single from–to for a shift without day rows. */
function hoursSummary(shift: Shift): string[] {
  return shift.days?.length
    ? shift.days.map((day) => `${dayLabel(day.day_of_week)} ${day.start_time} – ${day.end_time}`)
    : [`${shift.start_time} – ${shift.end_time}${shift.overnight ? ' (+1)' : ''}`]
}

const formOpen = ref(false)
const editing = ref<Shift | null>(null)
const form = reactive({
  code: '',
  name: '',
  days: [] as ShiftDay[],
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
  // A shift from before day rows existed: one Monday row with its hours — every other day falls back to them anyway.
  form.days = shift?.days?.length
    ? shift.days.map((day) => ({ ...day }))
    : [{ day_of_week: 1, start_time: shift?.start_time ?? '08:00', end_time: shift?.end_time ?? '17:00' }]
  form.break_minutes = String(shift?.break_minutes ?? 60)
  form.late_grace_minutes = String(shift?.late_grace_minutes ?? 10)
  form.early_leave_grace_minutes = String(shift?.early_leave_grace_minutes ?? 0)
  form.color = shift?.color ?? '#3b82f6'
  form.is_active = shift?.is_active ?? true
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

/** The next day not used yet, with the last row's hours — Monday 08:00–17:00 → Tuesday 08:00–17:00. */
function addDay() {
  const used = new Set(form.days.map((day) => day.day_of_week))
  const next = [1, 2, 3, 4, 5, 6, 7].find((day) => !used.has(day))
  if (next === undefined) return
  const last = form.days[form.days.length - 1]
  form.days.push({ day_of_week: next, start_time: last?.start_time ?? '08:00', end_time: last?.end_time ?? '17:00' })
}

function removeDay(index: number) {
  form.days.splice(index, 1)
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
          <p v-for="line in hoursSummary(row)" :key="line" class="mt-1 text-sm text-neutral-700">{{ line }}</p>
          <p v-if="(row.days?.length ?? 0) <= 1" class="text-xs text-neutral-500">{{ formatMinutes(row.work_minutes) }}</p>
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
          <p v-for="line in hoursSummary(row as Shift)" :key="line">{{ line }}</p>
          <p v-if="((row as Shift).days?.length ?? 0) <= 1" class="text-xs text-neutral-500">{{ formatMinutes(row.work_minutes) }}</p>
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
        </div>

        <!-- Day / From / To rows — same layout as Add Class's weekly schedule. -->
        <section>
          <div class="mb-1 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-neutral-800">{{ t('admin.classes.scheduleSection') }}</h3>
            <BaseButton type="button" variant="outline" size="sm" :disabled="form.days.length >= 7" @click="addDay">{{ t('admin.classes.addSchedule') }}</BaseButton>
          </div>
          <p class="mb-3 text-xs text-neutral-500">{{ t('admin.timeAttendance.shifts.endHint') }}</p>
          <p v-if="errors.days?.[0]" class="mb-2 text-sm text-danger-600">{{ errors.days[0] }}</p>
          <div
            v-for="(day, index) in form.days"
            :key="index"
            class="mb-2 grid grid-cols-2 items-end gap-3 rounded-lg border border-neutral-200 p-3 sm:grid-cols-[1fr_1fr_1fr_auto]"
          >
            <BaseSelect
              class="col-span-2 sm:col-span-1"
              :model-value="String(day.day_of_week)"
              :options="dayOptions"
              :label="t('admin.classes.day')"
              :error="errors[`days.${index}.day_of_week`]?.[0]"
              @update:model-value="day.day_of_week = Number($event)"
            />
            <BaseInput v-model="day.start_time" type="time" required :label="t('admin.classes.startTime')" :error="errors[`days.${index}.start_time`]?.[0]" />
            <BaseInput v-model="day.end_time" type="time" required :label="t('admin.classes.endTime')" :error="errors[`days.${index}.end_time`]?.[0]" />
            <button
              v-if="form.days.length > 1"
              type="button"
              class="col-span-2 mb-1.5 text-right text-sm font-medium text-danger-600 hover:text-red-700 sm:col-span-1"
              @click="removeDay(index)"
            >
              {{ t('common.remove') }}
            </button>
          </div>
        </section>

        <div class="grid gap-4 sm:grid-cols-2">
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

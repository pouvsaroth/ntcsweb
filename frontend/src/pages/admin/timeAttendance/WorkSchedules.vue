<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseMultiSelect from '@/components/ui/BaseMultiSelect.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { staffService, type Staff } from '@/services/staff'
import { shiftsService, WEEKDAYS, workSchedulesService, type Shift, type Weekday, type WorkSchedule } from '@/services/timeAttendance'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Attendance & Time > Work schedule — weekly patterns: which shift on
 * each weekday (or a day off), and who follows each. Staff not on any
 * schedule follow the default one. Shown as a week grid per schedule, which
 * stacks on a phone.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canManage = computed(() => auth.can('staff-attendance.manage'))

const { items, loading, error, fetch } = usePaginatedResource<WorkSchedule>((query) => workSchedulesService.list({ ...query, per_page: 100 }))

const shifts = ref<Shift[]>([])
const staff = ref<Staff[]>([])
const shiftById = computed(() => new Map(shifts.value.map((s) => [s.id, s])))
// Just the shift — its days and hours live on the shift itself (Shift tab's Day / From / To rows).
const shiftOptions = computed(() => shifts.value.filter((s) => s.is_active).map((s) => ({ value: String(s.id), label: s.name })))
const staffOptions = computed(() => staff.value.map((s) => ({ value: String(s.id), label: `${s.full_name} (${s.employee_code})` })))

/** The distinct shifts a schedule uses, in weekday order — usually just one. */
function shiftsOf(schedule: WorkSchedule): Shift[] {
  const ids = [...new Set(WEEKDAYS.map((day) => schedule[`${day}_shift_id`]).filter((id): id is number => !!id))]
  return ids.map((id) => shiftById.value.get(id)).filter((shift): shift is Shift => shift !== undefined)
}

/**
 * The weekdays a shift is worked: the days it has Day / From / To rows for.
 * A shift from before those rows existed has none — Monday to Friday, the
 * old default for a new schedule.
 */
function workedDays(shift: Shift): Weekday[] {
  return shift.days?.length ? shift.days.map((day) => WEEKDAYS[day.day_of_week - 1]) : WEEKDAYS.slice(0, 5)
}

// --- Form ----------------------------------------------------------------------

const formOpen = ref(false)
const editing = ref<WorkSchedule | null>(null)
const form = reactive({
  name: '',
  description: '',
  is_default: false,
  shift_id: '',
  staff_ids: [] as string[],
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const actionError = ref<string | null>(null)

async function open(schedule: WorkSchedule | null) {
  editing.value = schedule
  const full = schedule ? await workSchedulesService.get(schedule.id).catch(() => schedule) : null
  form.name = full?.name ?? ''
  form.description = full?.description ?? ''
  form.is_default = full?.is_default ?? items.value.length === 0
  // Its shift (the first one, for an older schedule mixing several); a new schedule starts on the first active shift.
  const current = full ? shiftsOf(full)[0] : shifts.value.find((s) => s.is_active)
  form.shift_id = current ? String(current.id) : ''
  form.staff_ids = (full?.staff ?? []).map((s) => String(s.id))
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const shift = shiftById.value.get(Number(form.shift_id))
  if (!shift) {
    errors.value = { shift_id: [t('admin.timeAttendance.schedules.shiftRequired')] }
    saving.value = false
    return
  }
  // Stored per weekday as before: the shift on each day it has hours for, a day off otherwise.
  const worked = new Set(workedDays(shift))
  const input = {
    name: form.name,
    description: form.description.trim() || null,
    is_default: form.is_default,
    staff_ids: form.staff_ids.map(Number),
    ...(Object.fromEntries(WEEKDAYS.map((d) => [`${d}_shift_id`, worked.has(d) ? shift.id : null])) as Record<`${Weekday}_shift_id`, number | null>),
  }
  try {
    if (editing.value) await workSchedulesService.update(editing.value.id, input)
    else await workSchedulesService.create(input)
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(schedule: WorkSchedule) {
  if (!(await confirmDialog.confirm({ message: t('admin.timeAttendance.schedules.deleteConfirm', { name: schedule.name }), danger: true }))) return
  actionError.value = null
  try {
    await workSchedulesService.remove(schedule.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(async () => {
  void fetch()
  const [shiftRows, staffRows] = await Promise.all([shiftsService.listAll().catch(() => []), staffService.listAll().catch(() => [])])
  shifts.value = shiftRows
  staff.value = staffRows
})
</script>

<template>
  <div>
    <div class="mb-4 flex items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">{{ t('admin.timeAttendance.schedules.hint') }}</p>
      <BaseButton v-if="canManage" :disabled="shifts.length === 0" @click="open(null)">{{ t('admin.timeAttendance.schedules.add') }}</BaseButton>
    </div>
    <p v-if="canManage && shifts.length === 0 && !loading" class="mb-4 text-sm text-neutral-500">{{ t('admin.timeAttendance.schedules.needShift') }}</p>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('admin.timeAttendance.schedules.empty') }}
    </p>

    <div v-else class="space-y-3">
      <section v-for="schedule in items" :key="schedule.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h2 class="flex items-center gap-2 font-semibold text-neutral-900">
              {{ schedule.name }}
              <BaseBadge v-if="schedule.is_default" variant="primary">{{ t('admin.timeAttendance.schedules.default') }}</BaseBadge>
            </h2>
            <p class="text-sm text-neutral-500">
              {{ t('admin.timeAttendance.schedules.staffCount', { count: schedule.staff_count ?? 0 }) }}<template v-if="schedule.is_default"> · {{ t('admin.timeAttendance.schedules.defaultHint') }}</template>
            </p>
          </div>
          <div v-if="canManage" class="flex gap-3">
            <EditIconButton @click="open(schedule)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(schedule)">{{ t('admin.organization.delete') }}</button>
          </div>
        </div>

        <!-- Just the shift — its days and hours are on the Shift tab. -->
        <div class="mt-3 flex flex-wrap gap-2">
          <span
            v-for="shift in shiftsOf(schedule)"
            :key="shift.id"
            class="inline-flex items-center gap-1.5 rounded-lg bg-neutral-50 px-3 py-1.5 text-sm font-medium text-neutral-800"
          >
            <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: shift.color ?? '#9ca3af' }" />{{ shift.name }}
          </span>
          <span v-if="shiftsOf(schedule).length === 0" class="text-sm text-neutral-400">{{ t('admin.timeAttendance.schedules.dayOff') }}</span>
        </div>
      </section>
    </div>

    <BaseModal v-model="formOpen" size="lg" :title="editing ? t('admin.timeAttendance.schedules.edit') : t('admin.timeAttendance.schedules.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <BaseInput v-model="form.name" required :label="t('admin.timeAttendance.schedules.name')" :error="errors.name?.[0]" />

        <BaseSelect
          v-model="form.shift_id"
          required
          :options="shiftOptions"
          :label="t('admin.timeAttendance.schedules.shift')"
          :hint="t('admin.timeAttendance.schedules.shiftHint')"
          :error="errors.shift_id?.[0]"
        />

        <BaseMultiSelect
          v-model="form.staff_ids"
          :options="staffOptions"
          :label="t('admin.timeAttendance.schedules.staff')"
          :placeholder="t('admin.timeAttendance.schedules.selectStaff')"
          :hint="t('admin.timeAttendance.schedules.staffHint')"
        />

        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.timeAttendance.schedules.makeDefault') }}
        </label>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

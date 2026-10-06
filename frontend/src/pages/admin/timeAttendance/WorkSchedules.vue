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
const shiftOptions = computed(() => [
  { value: '', label: t('admin.timeAttendance.schedules.dayOff') },
  ...shifts.value.filter((s) => s.is_active).map((s) => ({ value: String(s.id), label: `${s.name} (${s.start_time}–${s.end_time})` })),
])
const staffOptions = computed(() => staff.value.map((s) => ({ value: String(s.id), label: `${s.full_name} (${s.employee_code})` })))

function shiftOf(schedule: WorkSchedule, day: Weekday): Shift | undefined {
  const id = schedule[`${day}_shift_id`]
  return id ? shiftById.value.get(id) : undefined
}

// --- Form ----------------------------------------------------------------------

const formOpen = ref(false)
const editing = ref<WorkSchedule | null>(null)
const form = reactive({
  name: '',
  description: '',
  is_default: false,
  days: Object.fromEntries(WEEKDAYS.map((d) => [d, ''])) as Record<Weekday, string>,
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
  for (const day of WEEKDAYS) {
    const id = full ? full[`${day}_shift_id`] : null
    // A new schedule starts Monday–Friday on the first shift, weekend off.
    form.days[day] = id ? String(id) : !full && shifts.value[0] && !['saturday', 'sunday'].includes(day) ? String(shifts.value[0].id) : ''
  }
  form.staff_ids = (full?.staff ?? []).map((s) => String(s.id))
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    name: form.name,
    description: form.description.trim() || null,
    is_default: form.is_default,
    staff_ids: form.staff_ids.map(Number),
    ...(Object.fromEntries(WEEKDAYS.map((d) => [`${d}_shift_id`, form.days[d] ? Number(form.days[d]) : null])) as Record<`${Weekday}_shift_id`, number | null>),
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

        <!-- The week: seven columns on a computer, a list on a phone. -->
        <div class="mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-7">
          <div v-for="day in WEEKDAYS" :key="day" class="flex items-center justify-between rounded-lg bg-neutral-50 px-3 py-2 sm:block sm:text-center">
            <p class="text-xs font-semibold uppercase text-neutral-500">{{ t(`admin.timeAttendance.weekdays.${day}`) }}</p>
            <template v-if="shiftOf(schedule, day)">
              <p class="text-sm font-medium text-neutral-800">
                <span class="mr-1 inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: shiftOf(schedule, day)!.color ?? '#9ca3af' }" />{{ shiftOf(schedule, day)!.name }}
              </p>
              <p class="text-xs text-neutral-500">{{ shiftOf(schedule, day)!.start_time }}–{{ shiftOf(schedule, day)!.end_time }}</p>
            </template>
            <p v-else class="text-sm text-neutral-400">{{ t('admin.timeAttendance.schedules.dayOff') }}</p>
          </div>
        </div>
      </section>
    </div>

    <BaseModal v-model="formOpen" size="lg" :title="editing ? t('admin.timeAttendance.schedules.edit') : t('admin.timeAttendance.schedules.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <BaseInput v-model="form.name" required :label="t('admin.timeAttendance.schedules.name')" :error="errors.name?.[0]" />

        <div class="grid gap-3 sm:grid-cols-2">
          <BaseSelect
            v-for="day in WEEKDAYS"
            :key="day"
            v-model="form.days[day]"
            :options="shiftOptions"
            :label="t(`admin.timeAttendance.weekdays.${day}`)"
            :error="errors[`${day}_shift_id`]?.[0]"
          />
        </div>

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

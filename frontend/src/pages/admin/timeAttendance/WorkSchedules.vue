<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseMultiSelect from '@/components/ui/BaseMultiSelect.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { staffService, type Staff } from '@/services/staff'
import { shiftsService, WEEKDAYS, workSchedulesService, type Shift, type Weekday, type WorkSchedule } from '@/services/timeAttendance'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Attendance & Time > Staff shift — pick a shift and assign staff to
 * it. Staff not assigned to any follow the default one. Still a work
 * schedule underneath (a shift per weekday): the shift's own Day / From / To
 * rows decide its working days, and it's named after the shift.
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

// --- Staff list: every active staff member and the shift they work -------------
// Each schedule's own detail lists its staff; anyone on none follows the default.

const assignedSchedule = ref(new Map<number, WorkSchedule>())
const staffSearch = ref('')

async function loadAssignments() {
  const details = await Promise.all(items.value.map((schedule) => workSchedulesService.get(schedule.id).catch(() => null)))
  const map = new Map<number, WorkSchedule>()
  for (const detail of details) {
    for (const member of detail?.staff ?? []) map.set(member.id, detail!)
  }
  assignedSchedule.value = map
}

watch(items, () => void loadAssignments())

const staffRows = computed(() => {
  const defaultSchedule = items.value.find((schedule) => schedule.is_default) ?? null
  const needle = staffSearch.value.trim().toLocaleLowerCase()

  return staff.value
    .filter((member) => !needle || `${member.full_name} ${member.employee_code}`.toLocaleLowerCase().includes(needle))
    .map((member) => {
      const assigned = assignedSchedule.value.get(member.id) ?? null
      const schedule = assigned ?? defaultSchedule
      return { id: member.id, member, assigned, shifts: schedule ? shiftsOf(schedule) : [], viaDefault: assigned === null && schedule !== null }
    })
})

const staffColumns = computed(() => [
  { key: 'name', label: t('admin.timeAttendance.schedules.staff') },
  { key: 'position', label: t('admin.timeAttendance.schedules.position') },
  { key: 'shift', label: t('admin.timeAttendance.schedules.shift') },
  ...(canManage.value ? [{ key: 'actions', label: '' }] : []),
])

// --- One staff member's shift (the staff list's edit icon) -----------------------
// Moves them onto another staff shift, or off theirs so they follow the default.

const memberForm = reactive({ open: false, member: null as Staff | null, current: null as WorkSchedule | null, schedule_id: '' })
const memberError = ref<string | null>(null)
const memberSaving = ref(false)

/** The schedules to pick from, named by their shift; '' = none of them (the default, if there is one). */
const memberScheduleOptions = computed(() => {
  const defaultSchedule = items.value.find((schedule) => schedule.is_default) ?? null
  const none = defaultSchedule
    ? t('admin.timeAttendance.schedules.followDefault', { name: scheduleLabel(defaultSchedule) })
    : t('admin.timeAttendance.schedules.noShift')
  return [{ value: '', label: none }, ...items.value.filter((s) => !s.is_default).map((s) => ({ value: String(s.id), label: scheduleLabel(s) }))]
})

function scheduleLabel(schedule: WorkSchedule): string {
  return shiftsOf(schedule).map((shift) => shift.name).join(' / ') || schedule.name
}

function openMember(member: Staff, current: WorkSchedule | null) {
  memberForm.member = member
  memberForm.current = current
  // On the default schedule = following it, the same as on none.
  memberForm.schedule_id = current && !current.is_default ? String(current.id) : ''
  memberError.value = null
  memberForm.open = true
}

/** A schedule's staff ids, read fresh so nobody else's assignment is lost. */
async function staffIdsOf(scheduleId: number): Promise<number[]> {
  return ((await workSchedulesService.get(scheduleId)).staff ?? []).map((s) => s.id)
}

async function saveMember() {
  const member = memberForm.member
  if (!member) return
  memberSaving.value = true
  memberError.value = null
  try {
    const target = memberForm.schedule_id ? Number(memberForm.schedule_id) : null
    const current = memberForm.current?.id ?? null
    if (target !== null && target !== current) {
      // Joining one takes them off their previous one.
      await workSchedulesService.update(target, { staff_ids: [...(await staffIdsOf(target)), member.id] })
    } else if (target === null && current !== null) {
      await workSchedulesService.update(current, { staff_ids: (await staffIdsOf(current)).filter((id) => id !== member.id) })
    }
    memberForm.open = false
    await fetch()
  } catch (e) {
    memberError.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.saveFailed')
  } finally {
    memberSaving.value = false
  }
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
  // Never pre-ticked: a default schedule applies to every unassigned staff member, so it must be a deliberate choice.
  form.is_default = full?.is_default ?? false
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
    // No name of its own to type — it's named after its shift.
    name: shift.name,
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
  if (!(await confirmDialog.confirm({ message: t('admin.timeAttendance.schedules.deleteConfirm', { name: shiftsOf(schedule)[0]?.name ?? schedule.name }), danger: true }))) return
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
            <!-- Titled by its shift — its days and hours are on the Shift tab. -->
            <h2 class="flex flex-wrap items-center gap-2 font-semibold text-neutral-900">
              <span v-for="shift in shiftsOf(schedule)" :key="shift.id" class="inline-flex items-center gap-1.5">
                <span class="inline-block h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: shift.color ?? '#9ca3af' }" />{{ shift.name }}
              </span>
              <template v-if="shiftsOf(schedule).length === 0">{{ schedule.name }}</template>
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
      </section>
    </div>

    <!-- Every active staff member and the shift they work — cards on a phone, a table from sm up. -->
    <section v-if="!loading && items.length > 0" class="mt-8">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-semibold text-neutral-900">{{ t('admin.timeAttendance.schedules.staffListTitle') }}</h2>
        <input
          v-model="staffSearch"
          type="search"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:w-64"
          :placeholder="t('admin.timeAttendance.schedules.searchStaff')"
        />
      </div>

      <div class="sm:hidden">
        <p v-if="staffRows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
          {{ t('admin.timeAttendance.schedules.noStaff') }}
        </p>
        <div v-else class="space-y-2">
          <div v-for="row in staffRows" :key="row.member.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <p class="font-medium text-neutral-900">{{ row.member.full_name }}</p>
              <EditIconButton v-if="canManage" class="-mr-1 -mt-1" @click="openMember(row.member, row.assigned)" />
            </div>
            <p class="text-xs text-neutral-500">{{ [row.member.employee_code, row.member.position?.name].filter(Boolean).join(' · ') }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-neutral-800">
              <span v-for="shift in row.shifts" :key="shift.id" class="inline-flex items-center gap-1.5">
                <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: shift.color ?? '#9ca3af' }" />{{ shift.name }}
              </span>
              <span v-if="row.shifts.length === 0" class="text-neutral-400">—</span>
              <BaseBadge v-if="row.viaDefault" variant="neutral">{{ t('admin.timeAttendance.schedules.default') }}</BaseBadge>
            </div>
          </div>
        </div>
      </div>

      <div class="hidden sm:block">
        <DataTable :columns="staffColumns" :rows="staffRows" row-key="id" :empty-message="t('admin.timeAttendance.schedules.noStaff')">
          <template #cell-name="{ row }">
            <p class="font-medium text-neutral-900">{{ row.member.full_name }}</p>
            <p class="text-xs text-neutral-500">{{ row.member.employee_code }}</p>
          </template>
          <template #cell-position="{ row }">{{ row.member.position?.name ?? '—' }}</template>
          <template #cell-shift="{ row }">
            <div class="flex flex-wrap items-center gap-2">
              <span v-for="shift in row.shifts" :key="shift.id" class="inline-flex items-center gap-1.5">
                <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: shift.color ?? '#9ca3af' }" />{{ shift.name }}
              </span>
              <span v-if="row.shifts.length === 0" class="text-neutral-400">—</span>
              <BaseBadge v-if="row.viaDefault" variant="neutral">{{ t('admin.timeAttendance.schedules.default') }}</BaseBadge>
            </div>
          </template>
          <template #cell-actions="{ row }">
            <div class="flex justify-end"><EditIconButton @click="openMember(row.member, row.assigned)" /></div>
          </template>
        </DataTable>
      </div>
    </section>

    <BaseModal v-model="memberForm.open" :title="t('admin.timeAttendance.schedules.changeShift')">
      <form class="space-y-4" @submit.prevent="saveMember">
        <BaseAlert v-if="memberError" variant="danger">{{ memberError }}</BaseAlert>
        <p v-if="memberForm.member" class="text-sm text-neutral-700">
          <span class="font-medium text-neutral-900">{{ memberForm.member.full_name }}</span> · {{ memberForm.member.employee_code }}
        </p>
        <BaseSelect v-model="memberForm.schedule_id" :options="memberScheduleOptions" :label="t('admin.timeAttendance.schedules.shift')" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="memberForm.open = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="memberSaving" @click="saveMember">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="formOpen" size="lg" :title="editing ? t('admin.timeAttendance.schedules.edit') : t('admin.timeAttendance.schedules.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
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

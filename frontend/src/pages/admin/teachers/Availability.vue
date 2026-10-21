<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { teachersService, type Availability, type Teacher, type Unavailability } from '@/services/teachers'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Teacher > Availability: the hours a teacher cannot teach (every week,
 * or on dates), their approved leave (Leave Management) and the class times
 * that clash with a weekly slot.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('teachers.manage'))

const teachers = ref<Teacher[]>([])
const staffId = ref('')
const data = ref<Availability | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const teacherOptions = computed(() => teachers.value.map((x) => ({ value: String(x.id), label: x.name })))
const dayName = (d: number | null) => (d ? t(`teacherMgmt.days.d${d}`) : '')
const dayOptions = computed(() => [1, 2, 3, 4, 5, 6, 7].map((d) => ({ value: String(d), label: dayName(d) })))
const timeText = (u: { start_time: string | null; end_time: string | null }) => (u.start_time ? `${u.start_time}–${u.end_time}` : t('teacherMgmt.avail.allDay'))
const when = (u: Unavailability) =>
  u.kind === 'weekly'
    ? t('teacherMgmt.avail.everyDay', { day: dayName(u.day_of_week) })
    : u.from_date === u.to_date
      ? formatDate(u.from_date)
      : `${formatDate(u.from_date)} – ${formatDate(u.to_date)}`

async function load() {
  if (!staffId.value) return
  loading.value = true
  error.value = null
  try {
    data.value = await teachersService.availability(Number(staffId.value))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  } finally {
    loading.value = false
  }
}
function onTeacher(value: string) {
  staffId.value = value
  void load()
}

// --- Form ----------------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<Unavailability | null>(null)
const form = reactive({ kind: 'weekly' as 'weekly' | 'dates', day_of_week: '1', from_date: '', to_date: '', start_time: '', end_time: '', reason: '' })
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const kindOptions = computed(() => [
  { value: 'weekly', label: t('teacherMgmt.avail.weekly') },
  { value: 'dates', label: t('teacherMgmt.avail.dates') },
])

function open(u: Unavailability | null) {
  editing.value = u
  form.kind = u?.kind ?? 'weekly'
  form.day_of_week = String(u?.day_of_week ?? 1)
  form.from_date = u?.from_date ?? ''
  form.to_date = u && u.to_date !== u.from_date ? (u.to_date ?? '') : ''
  form.start_time = u?.start_time ?? ''
  form.end_time = u?.end_time ?? ''
  form.reason = u?.reason ?? ''
  errors.value = {}
  formOpen.value = true
}
async function save() {
  saving.value = true
  errors.value = {}
  const input = {
    staff_id: Number(staffId.value),
    kind: form.kind,
    day_of_week: form.kind === 'weekly' ? Number(form.day_of_week) : null,
    from_date: form.kind === 'dates' ? form.from_date || null : null,
    to_date: form.kind === 'dates' ? form.to_date || null : null,
    start_time: form.start_time || null,
    end_time: form.end_time || null,
    reason: form.reason.trim() || null,
  }
  try {
    if (editing.value) await teachersService.updateUnavailability(editing.value.id, input)
    else await teachersService.createUnavailability(input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else errors.value = { reason: [e instanceof ApiRequestError ? e.message : t('teacherMgmt.saveFailed')] }
  } finally {
    saving.value = false
  }
}
async function remove(u: Unavailability) {
  if (!(await confirmDialog.confirm({ message: t('teacherMgmt.avail.deleteConfirm'), danger: true }))) return
  await teachersService.removeUnavailability(u.id).catch(() => undefined)
  await load()
}

onMounted(async () => {
  try {
    teachers.value = await teachersService.list()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('teacherMgmt.loadFailed')
  }
  if (teachers.value.length) {
    staffId.value = String(teachers.value[0]!.id)
    await load()
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-72 max-w-full" :model-value="staffId" :options="teacherOptions" :placeholder="t('teacherMgmt.chooseTeacher')" @update:model-value="onTeacher" />
      <BaseSpinner v-if="loading" class="h-4 w-4" />
      <BaseButton v-if="canManage && staffId" class="ml-auto" @click="open(null)">{{ t('teacherMgmt.avail.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="!error && teachers.length === 0" variant="info" class="mb-4">{{ t('teacherMgmt.noTeachers') }}</BaseAlert>

    <template v-if="data">
      <BaseAlert v-if="data.clashes.length" variant="warning" class="mb-4">
        <p class="font-medium">{{ t('teacherMgmt.avail.clashes') }}</p>
        <p v-for="(c, i) in data.clashes" :key="i" class="text-sm">{{ c.class }} — {{ dayName(c.day_of_week) }} {{ c.start_time }}–{{ c.end_time }}</p>
      </BaseAlert>

      <div class="grid gap-4 lg:grid-cols-2">
        <!-- Cannot teach -->
        <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('teacherMgmt.avail.cannotTeach') }}</h2>
          <p v-if="data.unavailable.length === 0" class="mt-2 text-sm text-neutral-500">{{ t('teacherMgmt.avail.none') }}</p>
          <ul class="mt-2 divide-y divide-neutral-100">
            <li v-for="u in data.unavailable" :key="u.id" class="flex items-start gap-3 py-2">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-neutral-800">{{ when(u) }} · {{ timeText(u) }}</p>
                <p v-if="u.reason" class="text-xs text-neutral-500">{{ u.reason }}</p>
              </div>
              <div v-if="canManage" class="flex shrink-0 gap-2">
                <EditIconButton @click="open(u)" />
                <button type="button" class="text-xs font-medium text-danger-600" @click="remove(u)">{{ t('teacherMgmt.delete') }}</button>
              </div>
            </li>
          </ul>
        </section>

        <!-- Approved leave -->
        <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <div class="flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-neutral-800">{{ t('teacherMgmt.avail.leave') }}</h2>
            <RouterLink to="/admin/leave-management" class="text-xs text-secondary-700 hover:underline">{{ t('teacherMgmt.avail.leaveLink') }}</RouterLink>
          </div>
          <p v-if="data.leave.length === 0" class="mt-2 text-sm text-neutral-500">{{ t('teacherMgmt.avail.noLeave') }}</p>
          <ul class="mt-2 divide-y divide-neutral-100">
            <li v-for="l in data.leave" :key="l.id" class="py-2">
              <p class="text-sm font-medium text-neutral-800">
                {{ l.from_date === l.to_date ? formatDate(l.from_date) : `${formatDate(l.from_date)} – ${formatDate(l.to_date)}` }}
                <span v-if="l.days" class="font-normal text-neutral-500">· {{ t('teacherMgmt.avail.daysN', { count: l.days }) }}</span>
              </p>
              <p v-if="l.reason" class="text-xs text-neutral-500">{{ l.reason }}</p>
            </li>
          </ul>
        </section>
      </div>
    </template>

    <BaseModal v-model="formOpen" :title="editing ? t('teacherMgmt.avail.edit') : t('teacherMgmt.avail.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseSelect v-model="form.kind" :options="kindOptions" :label="t('teacherMgmt.avail.kind')" />
        <BaseSelect v-if="form.kind === 'weekly'" v-model="form.day_of_week" :options="dayOptions" :label="t('teacherMgmt.avail.day')" :error="errors.day_of_week?.[0]" />
        <div v-else class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.from_date" type="date" required :label="t('teacherMgmt.from')" :error="errors.from_date?.[0]" />
          <BaseInput v-model="form.to_date" type="date" :label="t('teacherMgmt.to')" :hint="t('teacherMgmt.avail.toHint')" :error="errors.to_date?.[0]" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.start_time" type="time" :label="t('teacherMgmt.startTime')" :error="errors.start_time?.[0]" />
          <BaseInput v-model="form.end_time" type="time" :label="t('teacherMgmt.endTime')" :error="errors.end_time?.[0]" />
        </div>
        <p class="text-xs text-neutral-500">{{ t('teacherMgmt.avail.timeHint') }}</p>
        <BaseInput v-model="form.reason" :label="t('teacherMgmt.reason')" :error="errors.reason?.[0]" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

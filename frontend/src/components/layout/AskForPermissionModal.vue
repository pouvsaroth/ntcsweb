<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { formatDays } from '@/services/leaveManagement'
import { myLeaveRequestsService, type LeaveDayPart, type MyLeaveEnrollment, type MyLeaveType } from '@/services/leaveRequests'
import { makeUpClassCourseLabel } from '@/services/makeUpClassRequests'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * A student's self-submitted leave/permission request — launched from
 * AdminSidebar's "My Profile" group ("Ask for Permission" entry), and from
 * the eApprovals "Forms" page's Quick Actions tile (see
 * admin/approvals/Forms.vue). Starts
 * pending; an admin approves or rejects it from the eApprovals "Approvals"
 * queue (see admin/approvals/Approvals.vue), and approving syncs matching
 * class days into the student's attendance as Excused
 * (LeaveRequestService::approve()).
 *
 * A staff member (once HRM > Leave Management has leave types) also picks
 * the leave type — seeing what's left of it this year — and, for one date, a
 * full day or a morning/afternoon half; the working days it takes are shown
 * as they pick. A student's form never has these (their type list is empty).
 *
 * A student instead picks the course it's for — their newest active course
 * is pre-selected, both dates default to today, and the time in/out come
 * from that course's class schedule for the From date (still editable).
 */
const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t } = useI18n()

const auth = useAuthStore()
const isStudent = computed(() => auth.hasRole('student'))

const form = reactive({ enrollment_id: '', leave_type_id: '', day_part: 'full' as LeaveDayPart, from_date: '', to_date: '', from_time: '', to_time: '', reason: '' })

// --- Student: course, and the class's times for the From date -----------------------
const enrollments = ref<MyLeaveEnrollment[]>([])
const enrollmentsLoading = ref(false)
const courseOptions = computed(() => enrollments.value.map((e) => ({ value: String(e.id), label: makeUpClassCourseLabel(e) })))

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** That class's session on the From date's weekday, else its first one of the week. */
function fillClassTimes() {
  const schedules = enrollments.value.find((e) => String(e.id) === form.enrollment_id)?.schedules ?? []
  if (schedules.length === 0) return
  const weekday = form.from_date ? ((new Date(`${form.from_date}T00:00:00`).getDay() + 6) % 7) + 1 : null
  const session = schedules.find((s) => s.day_of_week === weekday) ?? schedules[0]
  form.from_time = session.start_time
  form.to_time = session.end_time
}

watch(() => [form.enrollment_id, form.from_date], () => {
  if (isStudent.value) fillClassTimes()
})

// --- Staff: leave type, half days, days taken -------------------------------------

const leaveTypes = ref<MyLeaveType[]>([])
const staffMode = computed(() => leaveTypes.value.length > 0)
const selectedType = computed(() => leaveTypes.value.find((type) => String(type.id) === form.leave_type_id) ?? null)
const typeOptions = computed(() => leaveTypes.value.map((type) => ({ value: String(type.id), label: type.name })))
const oneDay = computed(() => form.from_date !== '' && (form.to_date === '' || form.to_date === form.from_date))
const canHalfDay = computed(() => oneDay.value && (selectedType.value?.allow_half_day ?? false))
const dayPartOptions = computed(() => [
  { value: 'full', label: t('leaveRequest.dayFull') },
  { value: 'morning', label: t('leaveRequest.dayMorning') },
  { value: 'afternoon', label: t('leaveRequest.dayAfternoon') },
])

const days = ref<number | null>(null)
let quoteTimer: ReturnType<typeof setTimeout> | undefined

watch(canHalfDay, (allowed) => {
  if (!allowed) form.day_part = 'full'
})

watch(
  () => [staffMode.value, form.from_date, form.to_date, form.day_part] as const,
  ([staff, from, to, dayPart]) => {
    clearTimeout(quoteTimer)
    days.value = null
    const end = to || from
    if (!staff || !from || end < from) return
    quoteTimer = setTimeout(async () => {
      try {
        days.value = await myLeaveRequestsService.quote({ from_date: from, to_date: end, day_part: dayPart })
      } catch {
        days.value = null
      }
    }, 300)
  },
)

const overBalance = computed(() => {
  const balance = selectedType.value?.balance
  return balance != null && days.value != null && days.value > balance.available
})
const attachments = ref<File[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const submitted = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return

    form.enrollment_id = ''
    form.leave_type_id = ''
    form.day_part = 'full'
    form.from_date = ''
    form.to_date = ''
    form.from_time = ''
    form.to_time = ''
    form.reason = ''
    attachments.value = []
    errors.value = {}
    generalError.value = null
    submitted.value = false
    days.value = null

    if (isStudent.value) {
      form.from_date = today()
      form.to_date = today()
      enrollmentsLoading.value = true
      myLeaveRequestsService
        .enrollments()
        .then((list) => {
          enrollments.value = list
          form.enrollment_id = list.length > 0 ? String(list[0].id) : ''
          fillClassTimes()
        })
        .catch(() => (enrollments.value = []))
        .finally(() => (enrollmentsLoading.value = false))
    }

    myLeaveRequestsService
      .types()
      .then((types) => (leaveTypes.value = types))
      .catch(() => (leaveTypes.value = []))
  },
)

function onFilesChange(event: Event) {
  const files = (event.target as HTMLInputElement).files
  if (!files) return

  attachments.value = [...attachments.value, ...Array.from(files)]
  ;(event.target as HTMLInputElement).value = ''
}

function removeFile(index: number) {
  attachments.value = attachments.value.filter((_, i) => i !== index)
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await myLeaveRequestsService.submit({
      enrollment_id: isStudent.value && form.enrollment_id ? Number(form.enrollment_id) : null,
      leave_type_id: staffMode.value && form.leave_type_id ? Number(form.leave_type_id) : null,
      day_part: staffMode.value ? form.day_part : null,
      from_date: form.from_date,
      // One date for a staff member's day: the To date may be left empty.
      to_date: staffMode.value && !form.to_date ? form.from_date : form.to_date,
      from_time: staffMode.value ? null : form.from_time || null,
      to_time: staffMode.value ? null : form.to_time || null,
      reason: form.reason,
      attachments: attachments.value,
    })
    submitted.value = true
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('leaveRequest.submitFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="t('leaveRequest.title')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <BaseAlert v-if="submitted" variant="success">{{ t('leaveRequest.submitSuccess') }}</BaseAlert>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseSelect
        v-if="isStudent"
        v-model="form.enrollment_id"
        required
        :options="courseOptions"
        :disabled="enrollmentsLoading"
        :label="t('makeUpClassRequest.course')"
        :error="errors.enrollment_id?.[0]"
      />

      <template v-if="staffMode">
        <BaseSelect
          v-model="form.leave_type_id"
          required
          :options="typeOptions"
          :placeholder="t('leaveRequest.pickType')"
          :label="t('leaveRequest.leaveType')"
          :error="errors.leave_type_id?.[0]"
        />
        <p v-if="selectedType" class="-mt-2 text-sm text-neutral-600">
          <template v-if="selectedType.balance">
            {{ t('leaveRequest.balanceLine', { available: formatDays(selectedType.balance.available), total: formatDays(selectedType.balance.total) }) }}
            <span v-if="selectedType.balance.pending > 0" class="text-neutral-500">· {{ t('leaveRequest.pendingLine', { days: formatDays(selectedType.balance.pending) }) }}</span>
          </template>
          <template v-else>{{ t('leaveRequest.noBalanceLimit') }}</template>
        </p>
      </template>

      <div class="grid grid-cols-2 gap-3">
        <BaseInput v-model="form.from_date" type="date" required :label="t('leaveRequest.fromDate')" :error="errors.from_date?.[0]" />
        <BaseInput
          v-model="form.to_date"
          type="date"
          :required="!staffMode"
          :label="t('leaveRequest.toDate')"
          :hint="staffMode ? t('leaveRequest.toDateHint') : undefined"
          :error="errors.to_date?.[0]"
        />
      </div>

      <template v-if="staffMode">
        <BaseSelect
          v-if="canHalfDay"
          :model-value="form.day_part"
          :options="dayPartOptions"
          :label="t('leaveRequest.dayPart')"
          :error="errors.day_part?.[0]"
          @update:model-value="form.day_part = $event as LeaveDayPart"
        />
        <p v-if="days !== null" class="rounded-lg px-3 py-2 text-sm" :class="overBalance ? 'bg-red-50 text-red-700' : 'bg-neutral-50 text-neutral-700'">
          {{ t('leaveRequest.daysTaken', { days: formatDays(days) }) }}
          <template v-if="overBalance"> — {{ t('leaveRequest.overBalance') }}</template>
        </p>
      </template>

      <div v-else class="grid grid-cols-2 gap-3">
        <BaseInput v-model="form.from_time" type="time" :label="t('leaveRequest.fromTime')" :error="errors.from_time?.[0]" />
        <BaseInput v-model="form.to_time" type="time" :label="t('leaveRequest.toTime')" :error="errors.to_time?.[0]" />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('leaveRequest.reason') }} <span class="text-danger-600">*</span>
        </label>
        <textarea
          v-model="form.reason"
          rows="3"
          required
          :placeholder="t('leaveRequest.reasonPlaceholder')"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.reason?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.reason[0] }}</p>
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('leaveRequest.attachments') }} <span v-if="selectedType?.requires_attachment" class="text-danger-600">*</span>
        </label>
        <input
          type="file"
          multiple
          accept="image/jpeg,image/png,image/webp,application/pdf"
          class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
          @change="onFilesChange"
        />
        <p class="mt-1.5 text-xs text-neutral-500">{{ t('leaveRequest.attachmentsHint') }}</p>
        <ul v-if="attachments.length" class="mt-2 space-y-1">
          <li
            v-for="(file, index) in attachments"
            :key="`${file.name}-${index}`"
            class="flex items-center justify-between rounded-lg bg-neutral-50 px-3 py-1.5 text-sm text-neutral-700"
          >
            <span class="truncate">{{ file.name }}</span>
            <button type="button" class="ml-2 shrink-0 text-neutral-400 hover:text-danger-600" @click="removeFile(index)">
              {{ t('common.remove') }}
            </button>
          </li>
        </ul>
        <p v-if="errors['attachments.0']?.[0] || errors.attachments?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors['attachments.0']?.[0] ?? errors.attachments?.[0] }}</p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton v-if="!submitted" :loading="submitting" @click="submit">{{ t('leaveRequest.submit') }}</BaseButton>
    </template>
  </BaseModal>
</template>

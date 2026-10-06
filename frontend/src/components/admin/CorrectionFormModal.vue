<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import type { Staff } from '@/services/staff'
import { attendanceCorrectionsService, clock, myCorrectionsService } from '@/services/staffAttendance'
import { ApiRequestError } from '@/types/api'

/**
 * Ask for one day's check-in / check-out to be fixed — the staff member's
 * own (no `staff` list) or, for HR, someone else's. It goes to E-Approvals;
 * once approved the times are written onto the day.
 */
const props = defineProps<{
  modelValue: boolean
  /** HR mode: pick who it's for. */
  staff?: Staff[]
  preset?: { date: string; checkIn?: string | null; checkOut?: string | null } | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const form = reactive({ staff_id: '', date: '', check_in: '', check_out: '', reason: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const forSomeone = computed(() => props.staff !== undefined)
const staffOptions = computed(() => (props.staff ?? []).map((s) => ({ value: String(s.id), label: `${s.full_name} (${s.employee_code})` })))

watch(
  () => [props.modelValue, props.preset] as const,
  ([open]) => {
    if (!open) return
    form.staff_id = ''
    form.date = props.preset?.date ?? ''
    form.check_in = props.preset?.checkIn ? clock(props.preset.checkIn) : ''
    form.check_out = props.preset?.checkOut ? clock(props.preset.checkOut) : ''
    form.reason = ''
    errors.value = {}
    saveError.value = null
  },
  { immediate: true },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = { date: form.date, check_in: form.check_in || null, check_out: form.check_out || null, reason: form.reason }
  try {
    if (forSomeone.value) await attendanceCorrectionsService.create({ ...input, staff_id: Number(form.staff_id) })
    else await myCorrectionsService.create(input)
    emit('saved')
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.timeAttendance.corrections.requestTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <BaseSelect
        v-if="forSomeone"
        v-model="form.staff_id"
        required
        :options="staffOptions"
        :placeholder="t('admin.timeAttendance.entry.selectStaff')"
        :label="t('admin.timeAttendance.entry.staff')"
        :error="errors.staff_id?.[0]"
      />
      <BaseInput v-model="form.date" type="date" required :disabled="!forSomeone && !!preset" :label="t('admin.timeAttendance.holidays.date')" :error="errors.date?.[0]" />
      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.check_in" type="time" :label="t('admin.timeAttendance.corrections.correctIn')" :error="errors.check_in?.[0]" />
        <BaseInput v-model="form.check_out" type="time" :label="t('admin.timeAttendance.corrections.correctOut')" :error="errors.check_out?.[0]" />
      </div>
      <p class="text-xs text-neutral-500">{{ t('admin.timeAttendance.corrections.timesHint') }}</p>
      <BaseInput v-model="form.reason" required :label="t('admin.timeAttendance.overtime.reason')" :placeholder="t('admin.timeAttendance.corrections.reasonHint')" :error="errors.reason?.[0]" />
      <p class="text-xs text-neutral-500">{{ t('admin.timeAttendance.overtime.goesToApprovals') }}</p>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" @click="save">{{ t('admin.timeAttendance.overtime.submit') }}</BaseButton>
    </template>
  </BaseModal>
</template>

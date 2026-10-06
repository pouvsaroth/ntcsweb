<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import type { Staff } from '@/services/staff'
import { clock, staffAttendanceService } from '@/services/staffAttendance'
import { ApiRequestError } from '@/types/api'

/**
 * HR sets (or fixes) one day's check-in / check-out for a staff member.
 * Times are the school's local time; a check-out earlier than the check-in
 * is the next morning (overnight). Late/early/overtime are worked out by the
 * server from the shift.
 */
const props = defineProps<{
  modelValue: boolean
  staff: Staff[]
  /** Fixed staff member + date (from a sheet cell or a log row). */
  preset?: { staffId: number; date: string; checkIn?: string | null; checkOut?: string | null; note?: string | null } | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const form = reactive({ staff_id: '', date: '', check_in: '', check_out: '', note: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const staffOptions = computed(() => props.staff.map((s) => ({ value: String(s.id), label: `${s.full_name} (${s.employee_code})` })))
const fixed = computed(() => props.preset != null)

watch(
  () => [props.modelValue, props.preset] as const,
  ([open]) => {
    if (!open) return
    const p = props.preset
    const now = new Date()
    form.staff_id = p ? String(p.staffId) : ''
    form.date = p?.date ?? `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
    form.check_in = p?.checkIn ? clock(p.checkIn) : ''
    form.check_out = p?.checkOut ? clock(p.checkOut) : ''
    form.note = p?.note ?? ''
    errors.value = {}
    saveError.value = null
  },
  { immediate: true },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    await staffAttendanceService.record({
      staff_id: Number(form.staff_id),
      date: form.date,
      check_in: form.check_in || null,
      check_out: form.check_out || null,
      note: form.note.trim() || null,
    })
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
  <BaseModal :model-value="modelValue" :title="t('admin.timeAttendance.entry.title')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <BaseSelect
        v-model="form.staff_id"
        required
        :disabled="fixed"
        :options="staffOptions"
        :placeholder="t('admin.timeAttendance.entry.selectStaff')"
        :label="t('admin.timeAttendance.entry.staff')"
        :error="errors.staff_id?.[0]"
      />
      <BaseInput v-model="form.date" type="date" required :disabled="fixed" :label="t('admin.timeAttendance.holidays.date')" :error="errors.date?.[0]" />
      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.check_in" type="time" :label="t('admin.timeAttendance.checkIn')" :error="errors.check_in?.[0]" />
        <BaseInput v-model="form.check_out" type="time" :label="t('admin.timeAttendance.checkOut')" :error="errors.check_out?.[0]" />
      </div>
      <p class="text-xs text-neutral-500">{{ t('admin.timeAttendance.entry.hint') }}</p>
      <BaseInput v-model="form.note" :label="t('admin.timeAttendance.entry.note')" :placeholder="t('admin.timeAttendance.entry.notePlaceholder')" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { timetableService, type TimetableOptions, type TimetableSlot } from '@/services/timetable'
import { ApiRequestError } from '@/types/api'

/** Ask to move one weekly time — another day/time, room or teacher (docs/academics/timetable.md). */
const props = defineProps<{ modelValue: boolean; slot: TimetableSlot | null; options: TimetableOptions }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()
const form = reactive({ day: '1', start: '', end: '', room: '', teacher: '', reason: '' })
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

const dayOptions = computed(() => [1, 2, 3, 4, 5, 6, 7].map((d) => ({ value: String(d), label: t(`timetable.days.d${d}`) })))
const roomOptions = computed(() => [{ value: '', label: t('timetable.noChange') }, ...props.options.rooms.map((r) => ({ value: String(r.id), label: r.name }))])
const teacherOptions = computed(() => [{ value: '', label: t('timetable.noChange') }, ...props.options.teachers.map((x) => ({ value: String(x.id), label: x.name }))])

watch(
  () => props.modelValue,
  (open) => {
    if (!open || !props.slot) return
    form.day = String(props.slot.day_of_week)
    form.start = props.slot.start_time
    form.end = props.slot.end_time
    form.room = ''
    form.teacher = ''
    form.reason = ''
    errors.value = {}
  },
)

async function save() {
  if (!props.slot) return
  saving.value = true
  errors.value = {}
  try {
    await timetableService.requestChange({
      class_schedule_id: props.slot.id,
      new_day_of_week: Number(form.day),
      new_start_time: form.start,
      new_end_time: form.end,
      new_classroom_id: form.room ? Number(form.room) : null,
      new_staff_id: form.teacher ? Number(form.teacher) : null,
      reason: form.reason.trim(),
    })
    emit('saved')
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else errors.value = { reason: [e instanceof ApiRequestError ? e.message : t('timetable.saveFailed')] }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('timetable.change.ask')" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="slot" class="space-y-4" @submit.prevent="save">
      <p class="text-sm text-neutral-600">
        {{ slot.class }} · {{ t(`timetable.days.d${slot.day_of_week}`) }} {{ slot.start_time }}–{{ slot.end_time }}<template v-if="slot.subject"> · {{ slot.subject }}</template>
      </p>
      <p v-if="errors.class_schedule_id" class="text-sm text-red-600">{{ errors.class_schedule_id[0] }}</p>
      <div class="grid gap-4 sm:grid-cols-3">
        <BaseSelect v-model="form.day" :options="dayOptions" :label="t('timetable.newDay')" />
        <BaseInput v-model="form.start" type="time" required :label="t('timetable.start')" :error="errors.new_start_time?.[0]" />
        <BaseInput v-model="form.end" type="time" required :label="t('timetable.end')" :error="errors.new_end_time?.[0]" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseSelect v-model="form.room" :options="roomOptions" :label="t('timetable.room')" />
        <BaseSelect v-model="form.teacher" :options="teacherOptions" :label="t('timetable.teacher')" />
      </div>
      <BaseInput v-model="form.reason" required :label="t('timetable.reason')" :error="errors.reason?.[0]" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!form.reason.trim()" @click="save">{{ t('timetable.change.send') }}</BaseButton>
    </template>
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { enrollmentsService, type ResponsibleStaffOption } from '@/services/enrollments'
import { ApiRequestError } from '@/types/api'

/**
 * Class > Students > Responsible Staff: one staff member for all the checked
 * students, from a date (today by default) and optionally to a date.
 */
const props = defineProps<{ modelValue: boolean; enrollmentIds: number[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [count: number] }>()

const { t } = useI18n()

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive({ staff_id: '', from_date: '', to_date: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const saving = ref(false)

const staff = ref<ResponsibleStaffOption[]>([])
const staffOptions = computed(() => staff.value.map((s) => ({ value: String(s.id), label: s.name, hint: s.employee_code ?? undefined })))

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    Object.assign(form, { staff_id: '', from_date: todayIso(), to_date: '' })
    errors.value = {}
    generalError.value = null
    if (staff.value.length === 0) {
      try {
        staff.value = await enrollmentsService.responsibleStaffOptions()
      } catch (error) {
        generalError.value = error instanceof ApiRequestError ? error.message : t('admin.classStudents.responsibleStaffFailed')
      }
    }
  },
)

async function save() {
  saving.value = true
  errors.value = {}
  generalError.value = null
  try {
    const result = await enrollmentsService.assignResponsibleStaff({
      enrollment_ids: props.enrollmentIds,
      staff_id: Number(form.staff_id) || 0,
      from_date: form.from_date,
      to_date: form.to_date || null,
    })
    emit('saved', result.saved)
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.classStudents.responsibleStaffFailed')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.classStudents.responsibleStaffTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <p class="text-sm text-neutral-600">{{ t('admin.classStudents.responsibleStaffFor', { count: enrollmentIds.length }) }}</p>

      <SearchableSelect
        v-model="form.staff_id"
        required
        :options="staffOptions"
        :label="t('admin.classStudents.responsibleStaffStaff')"
        :placeholder="t('admin.classStudents.responsibleStaffPick')"
        :error="errors.staff_id?.[0]"
      />
      <div class="grid grid-cols-2 gap-3">
        <BaseInput v-model="form.from_date" type="date" required :label="t('admin.classStudents.responsibleStaffFrom')" :error="errors.from_date?.[0]" />
        <BaseInput v-model="form.to_date" type="date" :label="t('admin.classStudents.responsibleStaffTo')" :error="errors.to_date?.[0]" />
      </div>
      <p class="text-xs text-neutral-500">{{ t('admin.classStudents.responsibleStaffHint') }}</p>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!form.staff_id || !form.from_date" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

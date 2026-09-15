<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { classesService } from '@/services/classes'
import { enrollmentsService, type Enrollment } from '@/services/enrollments'
import { ApiRequestError } from '@/types/api'

/**
 * Reseats a student within their *current* class — deliberately has no
 * class/course picker at all (unlike EnrollmentTransferModal), so someone
 * granted only `enrollments.change-table` has no way to move a student
 * anywhere else even if they wanted to. See ChangeEnrollmentTableRequest.
 */
const props = defineProps<{
  modelValue: boolean
  enrollment: Enrollment | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const tableId = ref<number | null>(null)
const tables = ref<{ total_tables: number; available: { id: number; name: string }[] } | null>(null)
const loadingTables = ref(false)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const tableOptions = computed(() => {
  const options = (tables.value?.available ?? []).map((tbl) => ({ value: String(tbl.id), label: tbl.name }))
  // "available" excludes the student's own current seat too — add it back so "keep the same table" is still a choice.
  const current = props.enrollment?.table
  if (current && !options.some((o) => o.value === String(current.id))) {
    options.unshift({ value: String(current.id), label: current.name })
  }
  return options
})
const tableRequired = computed(() => (tables.value?.total_tables ?? 0) > 0)

watch(
  () => [props.modelValue, props.enrollment] as const,
  async ([open, enrollment]) => {
    if (!open || !enrollment) return

    tableId.value = enrollment.table_id
    errors.value = {}
    generalError.value = null

    loadingTables.value = true
    try {
      tables.value = await classesService.availableTables(enrollment.class.id)
    } finally {
      loadingTables.value = false
    }
  },
  { immediate: true },
)

async function submit() {
  if (!props.enrollment) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await enrollmentsService.changeTable(props.enrollment.id, tableId.value)
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.enrollments.changeTableFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.enrollments.changeTable')" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="enrollment" class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
        {{ enrollment.student.full_name }} — {{ enrollment.class.name }}
      </div>

      <BaseSelect
        :model-value="tableId !== null ? String(tableId) : ''"
        :options="tableOptions"
        :disabled="loadingTables"
        :required="tableRequired"
        :placeholder="loadingTables ? t('common.loading') : t('admin.enrollments.selectTable')"
        :label="t('admin.enrollments.table')"
        :error="errors.table_id?.[0]"
        @update:model-value="tableId = $event ? Number($event) : null"
      />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { enrollmentsService, type Enrollment, type EnrollmentStatus, type EnrollmentStatusHistoryEntry } from '@/services/enrollments'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  enrollment: Enrollment | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t } = useI18n()

function statusKey(status: EnrollmentStatus): string {
  return status
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

function statusLabel(status: EnrollmentStatus): string {
  return t(`admin.enrollments.status${statusKey(status)}`)
}

const entries = ref<EnrollmentStatusHistoryEntry[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

watch(
  () => [props.modelValue, props.enrollment] as const,
  async ([open, enrollment]) => {
    if (!open || !enrollment) return

    loading.value = true
    error.value = null
    try {
      entries.value = await enrollmentsService.statusHistory(enrollment.id)
    } catch (e) {
      error.value = e instanceof ApiRequestError ? e.message : t('admin.enrollments.statusHistoryLoadFailed')
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.enrollments.statusHistory')" @update:model-value="emit('update:modelValue', $event)">
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-6"><BaseSpinner /></div>

    <p v-else-if="entries.length === 0" class="py-4 text-center text-sm text-neutral-500">
      {{ t('admin.enrollments.statusHistoryEmpty') }}
    </p>

    <ul v-else class="space-y-3">
      <li v-for="entry in entries" :key="entry.id" class="rounded-lg border border-neutral-200 p-3 text-sm">
        <div class="flex items-center justify-between">
          <span class="font-medium text-neutral-900">{{ statusLabel(entry.from_status) }} → {{ statusLabel(entry.to_status) }}</span>
          <span class="text-xs text-neutral-400">{{ new Date(entry.created_at).toLocaleString() }}</span>
        </div>
        <p v-if="entry.reason" class="mt-1 text-neutral-600">{{ entry.reason }}</p>
        <p v-if="entry.effective_date" class="mt-0.5 text-xs text-neutral-500">
          {{ t('admin.enrollments.statusEffectiveDate') }}: {{ entry.effective_date }}
        </p>
        <p v-if="entry.changed_by" class="mt-0.5 text-xs text-neutral-400">{{ entry.changed_by }}</p>
      </li>
    </ul>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
    </template>
  </BaseModal>
</template>

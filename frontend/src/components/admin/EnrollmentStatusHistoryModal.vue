<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import {
  enrollmentsService,
  type Enrollment,
  type EnrollmentStatus,
  type EnrollmentStatusHistoryEntry,
  type EnrollmentTransferHistoryEntry,
} from '@/services/enrollments'
import { ApiRequestError } from '@/types/api'
import { formatDate, formatDateTime } from '@/utils/date'

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

const statusEntries = ref<EnrollmentStatusHistoryEntry[]>([])
const transferEntries = ref<EnrollmentTransferHistoryEntry[]>([])

// One timeline, newest first: status changes and class/table/course changes
// live in separate tables (see EnrollmentTransferHistory on the backend).
type TimelineEntry =
  | { kind: 'status'; key: string; created_at: string; entry: EnrollmentStatusHistoryEntry }
  | { kind: 'transfer'; key: string; created_at: string; entry: EnrollmentTransferHistoryEntry }

const entries = computed<TimelineEntry[]>(() =>
  [
    ...statusEntries.value.map((entry) => ({ kind: 'status' as const, key: `s${entry.id}`, created_at: entry.created_at, entry })),
    ...transferEntries.value.map((entry) => ({ kind: 'transfer' as const, key: `t${entry.id}`, created_at: entry.created_at, entry })),
  ].sort((a, b) => b.created_at.localeCompare(a.created_at)),
)

/** Only the parts that actually changed — a table-only move shouldn't show "Class: A → A". */
function transferLines(entry: EnrollmentTransferHistoryEntry): { label: string; from: string; to: string }[] {
  const pairs: [string, string | null, string | null][] = [
    [t('admin.enrollments.class'), entry.from_class, entry.to_class],
    [t('admin.enrollments.table'), entry.from_table, entry.to_table],
    [t('admin.enrollments.package'), entry.from_course_package, entry.to_course_package],
  ]
  return pairs.filter(([, from, to]) => from !== to).map(([label, from, to]) => ({ label, from: from ?? '—', to: to ?? '—' }))
}
const loading = ref(false)
const error = ref<string | null>(null)

watch(
  () => [props.modelValue, props.enrollment] as const,
  async ([open, enrollment]) => {
    if (!open || !enrollment) return

    loading.value = true
    error.value = null
    try {
      ;[statusEntries.value, transferEntries.value] = await Promise.all([
        enrollmentsService.statusHistory(enrollment.id),
        enrollmentsService.transferHistory(enrollment.id),
      ])
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
      <li v-for="item in entries" :key="item.key" class="rounded-lg border border-neutral-200 p-3 text-sm">
        <template v-if="item.kind === 'status'">
          <div class="flex items-center justify-between gap-2">
            <span class="font-medium text-neutral-900">{{ statusLabel(item.entry.from_status) }} → {{ statusLabel(item.entry.to_status) }}</span>
            <span class="shrink-0 text-xs text-neutral-400">{{ formatDateTime(item.created_at) }}</span>
          </div>
          <p v-if="item.entry.reason" class="mt-1 text-neutral-600">{{ item.entry.reason }}</p>
          <p v-if="item.entry.effective_date" class="mt-0.5 text-xs text-neutral-500">
            {{ t('admin.enrollments.statusEffectiveDate') }}: {{ formatDate(item.entry.effective_date) }}
          </p>
        </template>
        <template v-else>
          <div class="flex items-start justify-between gap-2">
            <div class="space-y-0.5">
              <p v-for="line in transferLines(item.entry)" :key="line.label" class="flex flex-wrap gap-x-1 text-neutral-900">
                <span class="text-neutral-500">{{ line.label }}:</span>
                <span class="font-medium">{{ line.from }} → {{ line.to }}</span>
              </p>
            </div>
            <span class="shrink-0 text-xs text-neutral-400">{{ formatDateTime(item.created_at) }}</span>
          </div>
        </template>
        <p v-if="item.entry.changed_by" class="mt-0.5 text-xs text-neutral-400">{{ item.entry.changed_by }}</p>
      </li>
    </ul>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
    </template>
  </BaseModal>
</template>

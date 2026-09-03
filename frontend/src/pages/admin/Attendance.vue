<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  attendanceService,
  attendanceStatuses,
  type AttendanceEntryInput,
  type AttendanceRecord,
  type AttendanceRosterEntry,
  type AttendanceStatusValue,
} from '@/services/attendance'
import { classesService, type SchoolClass } from '@/services/classes'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

/** yyyy-MM-dd in the viewer's local time — matches EnrollmentPackageForm's own helper. */
function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const statusVariant: Record<AttendanceStatusValue, 'success' | 'danger' | 'warning' | 'neutral'> = {
  PRESENT: 'success',
  ABSENT: 'danger',
  LATE: 'warning',
  EXCUSED: 'neutral',
}

// --- Take attendance -------------------------------------------------

const classes = ref<SchoolClass[]>([])
const loadingClasses = ref(true)
const classId = ref<number | null>(null)
const date = ref(today())
const roster = ref<AttendanceRosterEntry[]>([])
const rosterEntries = reactive<Record<number, { status: AttendanceStatusValue; remarks: string }>>({})
const loadingRoster = ref(false)
const rosterError = ref<string | null>(null)
const saving = ref(false)
const saveError = ref<string | null>(null)
const saved = ref(false)

const classOptions = computed(() => classes.value.map((c) => ({ value: String(c.id), label: c.name })))

async function loadRoster() {
  if (!classId.value) return
  loadingRoster.value = true
  rosterError.value = null
  saved.value = false

  try {
    roster.value = await attendanceService.roster(classId.value, date.value)
    for (const entry of roster.value) {
      rosterEntries[entry.enrollment_id] = {
        status: entry.status ?? 'PRESENT',
        remarks: entry.remarks ?? '',
      }
    }
  } catch (error) {
    rosterError.value = error instanceof ApiRequestError ? error.message : t('admin.attendance.loadFailed')
    roster.value = []
  } finally {
    loadingRoster.value = false
  }
}

function markAll(status: AttendanceStatusValue) {
  for (const entry of roster.value) {
    rosterEntries[entry.enrollment_id].status = status
  }
}

async function save() {
  if (!classId.value || roster.value.length === 0) return

  saving.value = true
  saveError.value = null
  saved.value = false

  try {
    const entries: AttendanceEntryInput[] = roster.value.map((entry) => ({
      enrollment_id: entry.enrollment_id,
      status: rosterEntries[entry.enrollment_id].status,
      remarks: rosterEntries[entry.enrollment_id].remarks || null,
    }))

    await attendanceService.save(classId.value, date.value, entries)
    saved.value = true
    await loadRoster()
    await fetchHistory()
  } catch (error) {
    saveError.value = error instanceof ApiRequestError ? error.message : t('admin.attendance.saveFailed')
  } finally {
    saving.value = false
  }
}

watch([classId, date], () => void loadRoster())

// --- History -----------------------------------------------------------

const {
  items: historyItems,
  meta: historyMeta,
  loading: historyLoading,
  error: historyError,
  setPage: setHistoryPage,
  fetch: fetchHistory,
} = usePaginatedResource<AttendanceRecord>((query) => attendanceService.list(query))

const historyColumns = [
  { key: 'date', label: t('admin.attendance.columnDate') },
  { key: 'student', label: t('admin.attendance.columnStudent') },
  { key: 'class', label: t('admin.attendance.columnClass') },
  { key: 'status', label: t('admin.attendance.columnStatus') },
  { key: 'remarks', label: t('admin.attendance.columnRemarks') },
]

function statusLabel(status: AttendanceStatusValue): string {
  return t(`admin.attendance.status${status.charAt(0)}${status.slice(1).toLowerCase()}`)
}

onMounted(async () => {
  loadingClasses.value = true
  try {
    classes.value = await classesService.listAll()
  } finally {
    loadingClasses.value = false
  }
  await fetchHistory()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.attendance.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.attendance.subtitle') }}</p>
    </div>

    <div class="mb-8 rounded-[--radius-card] border border-neutral-200 bg-white p-5">
      <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <BaseSelect
          :model-value="classId !== null ? String(classId) : ''"
          :options="classOptions"
          :disabled="loadingClasses"
          :placeholder="t('admin.attendance.selectClass')"
          :label="t('admin.attendance.class')"
          @update:model-value="classId = $event ? Number($event) : null"
        />
        <BaseInput v-model="date" type="date" :label="t('admin.attendance.date')" />
      </div>

      <BaseAlert v-if="rosterError" variant="danger" class="mb-4">{{ rosterError }}</BaseAlert>
      <BaseAlert v-if="saveError" variant="danger" class="mb-4">{{ saveError }}</BaseAlert>
      <BaseAlert v-if="saved" variant="success" class="mb-4">{{ t('admin.attendance.saveSuccess') }}</BaseAlert>

      <template v-if="classId">
        <div v-if="loadingRoster" class="py-8 text-center text-sm text-neutral-400">{{ t('common.loading') }}</div>

        <template v-else-if="roster.length > 0">
          <div class="mb-3 flex flex-wrap gap-2">
            <span class="self-center text-xs font-medium text-neutral-500">{{ t('admin.attendance.markAll') }}:</span>
            <button
              v-for="status in attendanceStatuses"
              :key="status"
              type="button"
              class="rounded-full px-2.5 py-1 text-xs font-medium"
              :class="statusVariant[status] === 'success' ? 'bg-green-100 text-green-700 hover:bg-green-200' : statusVariant[status] === 'danger' ? 'bg-red-100 text-red-700 hover:bg-red-200' : statusVariant[status] === 'warning' ? 'bg-amber-100 text-amber-700 hover:bg-amber-200' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'"
              @click="markAll(status)"
            >
              {{ statusLabel(status) }}
            </button>
          </div>

          <div class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
            <div v-for="entry in roster" :key="entry.enrollment_id" class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:gap-4">
              <div class="sm:w-56">
                <p class="font-medium text-neutral-800">{{ entry.student.name }}</p>
                <p class="text-xs text-neutral-500">{{ entry.student.student_code }}</p>
              </div>

              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="status in attendanceStatuses"
                  :key="status"
                  type="button"
                  class="rounded-full border px-2.5 py-1 text-xs font-medium transition-colors"
                  :class="
                    rosterEntries[entry.enrollment_id]?.status === status
                      ? statusVariant[status] === 'success'
                        ? 'border-green-500 bg-green-500 text-white'
                        : statusVariant[status] === 'danger'
                          ? 'border-red-500 bg-red-500 text-white'
                          : statusVariant[status] === 'warning'
                            ? 'border-amber-500 bg-amber-500 text-white'
                            : 'border-neutral-500 bg-neutral-500 text-white'
                      : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'
                  "
                  @click="rosterEntries[entry.enrollment_id].status = status"
                >
                  {{ statusLabel(status) }}
                </button>
              </div>

              <input
                v-model="rosterEntries[entry.enrollment_id].remarks"
                type="text"
                :placeholder="t('admin.attendance.remarksPlaceholder')"
                class="flex-1 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
              />
            </div>
          </div>

          <div class="mt-4">
            <BaseButton :loading="saving" @click="save">{{ t('admin.attendance.save') }}</BaseButton>
          </div>
        </template>

        <p v-else class="py-8 text-center text-sm text-neutral-400">{{ t('admin.attendance.noStudents') }}</p>
      </template>
      <p v-else class="py-8 text-center text-sm text-neutral-400">{{ t('admin.attendance.pickClassPrompt') }}</p>
    </div>

    <div>
      <h2 class="mb-3 text-base font-semibold text-neutral-900">{{ t('admin.attendance.historyTitle') }}</h2>

      <BaseAlert v-if="historyError" variant="danger" class="mb-4">{{ historyError }}</BaseAlert>

      <DataTable
        :columns="historyColumns"
        :rows="historyItems"
        row-key="id"
        :loading="historyLoading"
        :empty-message="t('admin.attendance.emptyMessage')"
      >
        <template #cell-student="{ row }">{{ row.student?.name }}</template>
        <template #cell-class="{ row }">{{ row.class?.name }}</template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusVariant[row.status]">{{ statusLabel(row.status) }}</BaseBadge>
        </template>
        <template #cell-remarks="{ row }">{{ row.remarks ?? '—' }}</template>
      </DataTable>

      <BasePagination v-if="historyMeta" :meta="historyMeta" sticky class="mt-4" @update:page="setHistoryPage" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import {
  attendanceService,
  attendanceStatuses,
  type AttendanceEntryInput,
  type AttendanceRosterEntry,
  type AttendanceStatusValue,
} from '@/services/attendance'
import { classesService, type SchoolClass } from '@/services/classes'
import { ApiRequestError } from '@/types/api'

/**
 * The "View" action on the Classes list: pick several classes there, land
 * here, and mark attendance for every one of their students in one screen.
 * There is no dedicated multi-class backend endpoint — each class's roster
 * is fetched with the existing per-class `attendanceService.roster()`, and
 * saving fans back out into one `attendanceService.save()` call per class
 * (see save() below), keyed by the `class_id` this page tags onto each
 * fetched entry since AttendanceRosterEntry itself doesn't carry one.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()

interface TaggedEntry extends AttendanceRosterEntry {
  class_id: number
}

interface ClassGroup {
  class_id: number
  class_name: string
  entries: TaggedEntry[]
}

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

const classIds = computed<number[]>(() => {
  const raw = route.query.class_ids
  const csv = typeof raw === 'string' ? raw : ''
  return csv
    .split(',')
    .map((s) => Number(s.trim()))
    .filter((n) => Number.isInteger(n) && n > 0)
})

const classNames = ref<Record<number, string>>({})
const date = ref(today())
const groups = ref<ClassGroup[]>([])
const rosterEntries = reactive<Record<number, { status: AttendanceStatusValue; remarks: string }>>({})
const loadingClasses = ref(true)
const loadingRoster = ref(false)
const loadError = ref<string | null>(null)
const saving = ref(false)
const saveError = ref<string | null>(null)
const saved = ref(false)

const totalStudents = computed(() => groups.value.reduce((sum, g) => sum + g.entries.length, 0))

async function loadRoster() {
  if (classIds.value.length === 0) {
    groups.value = []
    return
  }

  loadingRoster.value = true
  loadError.value = null
  saved.value = false

  try {
    const results = await Promise.all(
      classIds.value.map(async (classId) => {
        const entries = await attendanceService.roster(classId, date.value)
        return { class_id: classId, class_name: classNames.value[classId] ?? `#${classId}`, entries: entries.map((e) => ({ ...e, class_id: classId })) }
      }),
    )

    groups.value = results.filter((g) => g.entries.length > 0)

    for (const group of groups.value) {
      for (const entry of group.entries) {
        rosterEntries[entry.enrollment_id] = {
          status: entry.status ?? 'PRESENT',
          remarks: entry.remarks ?? '',
        }
      }
    }
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.attendance.loadFailed')
    groups.value = []
  } finally {
    loadingRoster.value = false
  }
}

function markAll(status: AttendanceStatusValue) {
  for (const group of groups.value) {
    for (const entry of group.entries) {
      rosterEntries[entry.enrollment_id].status = status
    }
  }
}

function statusLabel(status: AttendanceStatusValue): string {
  return t(`admin.attendance.status${status.charAt(0)}${status.slice(1).toLowerCase()}`)
}

async function save() {
  if (groups.value.length === 0) return

  saving.value = true
  saveError.value = null
  saved.value = false

  try {
    await Promise.all(
      groups.value.map((group) => {
        const entries: AttendanceEntryInput[] = group.entries.map((entry) => ({
          enrollment_id: entry.enrollment_id,
          status: rosterEntries[entry.enrollment_id].status,
          remarks: rosterEntries[entry.enrollment_id].remarks || null,
        }))
        return attendanceService.save(group.class_id, date.value, entries)
      }),
    )
    saved.value = true
    await loadRoster()
  } catch (error) {
    saveError.value = error instanceof ApiRequestError ? error.message : t('admin.attendance.saveFailed')
  } finally {
    saving.value = false
  }
}

watch(date, () => void loadRoster())

onMounted(async () => {
  loadingClasses.value = true
  try {
    const all = await classesService.listAll()
    const byId: Record<number, string> = {}
    for (const c of all as SchoolClass[]) byId[c.id] = c.name
    classNames.value = byId
  } finally {
    loadingClasses.value = false
  }

  await loadRoster()
})
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.attendance.multiTitle') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">
          {{ t('admin.attendance.multiSubtitle', { count: classIds.length }) }}
        </p>
      </div>
      <BaseButton variant="outline" size="sm" @click="router.push('/admin/classes')">{{ t('admin.classes.title') }}</BaseButton>
    </div>

    <div v-if="classIds.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('admin.attendance.multiNoClasses') }}
    </div>

    <template v-else>
      <div class="mb-6 rounded-[--radius-card] border border-neutral-200 bg-white p-5">
        <BaseInput v-model="date" type="date" :label="t('admin.attendance.date')" class="max-w-xs" />

        <BaseAlert v-if="loadError" variant="danger" class="mt-4">{{ loadError }}</BaseAlert>
        <BaseAlert v-if="saveError" variant="danger" class="mt-4">{{ saveError }}</BaseAlert>
        <BaseAlert v-if="saved" variant="success" class="mt-4">{{ t('admin.attendance.saveSuccess') }}</BaseAlert>

        <div v-if="loadingClasses || loadingRoster" class="py-8 text-center"><BaseSpinner class="mx-auto" /></div>

        <template v-else-if="totalStudents > 0">
          <div class="mt-4 flex flex-wrap gap-2">
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

          <div v-for="group in groups" :key="group.class_id" class="mt-5">
            <h2 class="mb-2 text-sm font-semibold text-neutral-800">{{ group.class_name }} <span class="font-normal text-neutral-400">({{ group.entries.length }})</span></h2>
            <div class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
              <div v-for="entry in group.entries" :key="entry.enrollment_id" class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:gap-4">
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
          </div>

          <div class="mt-5">
            <BaseButton :loading="saving" @click="save">{{ t('admin.attendance.save') }}</BaseButton>
          </div>
        </template>

        <p v-else class="py-8 text-center text-sm text-neutral-400">{{ t('admin.attendance.noStudents') }}</p>
      </div>
    </template>
  </div>
</template>

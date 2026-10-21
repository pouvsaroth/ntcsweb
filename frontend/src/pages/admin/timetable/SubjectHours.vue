<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { timetableService, type SubjectHours, type TimetableOptions } from '@/services/timetable'
import { ApiRequestError } from '@/types/api'

/**
 * Timetable > Subject schedule: per subject of a class, the hours a week in
 * the timetable next to what the curriculum of its grade asks for and what
 * teacher assignments say — so missing or extra hours show.
 */
const { t } = useI18n()

const options = ref<TimetableOptions>({ classes: [], teachers: [], rooms: [], subjects: [], me: null })
const classId = ref('')
const data = ref<SubjectHours | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const classOptions = computed(() => options.value.classes.map((c) => ({ value: String(c.id), label: c.academic_year ? `${c.name} · ${c.academic_year}` : c.name })))
const rows = computed(() => (data.value?.subjects ?? []).map((r, i) => ({ ...r, key: r.book_id ?? `none${i}` })))
const diff = (r: SubjectHours['subjects'][number]) => (r.curriculum_hours == null ? null : Math.round((r.timetable_hours - r.curriculum_hours) * 100) / 100)

async function load() {
  if (!classId.value) return
  loading.value = true
  error.value = null
  try {
    data.value = await timetableService.subjects(Number(classId.value))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('timetable.loadFailed')
  } finally {
    loading.value = false
  }
}
function onClass(value: string) {
  classId.value = value
  void load()
}

const columns = computed(() => [
  { key: 'subject', label: t('timetable.subject') },
  { key: 'timetable', label: t('timetable.subjects.timetable') },
  { key: 'curriculum', label: t('timetable.subjects.curriculum') },
  { key: 'assigned', label: t('timetable.subjects.assigned') },
  { key: 'diff', label: t('timetable.subjects.diff') },
])

onMounted(async () => {
  options.value = await timetableService.options().catch(() => options.value)
  if (options.value.classes.length) {
    classId.value = String(options.value.classes[0]!.id)
    await load()
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-72 max-w-full" :model-value="classId" :options="classOptions" :placeholder="t('timetable.chooseClass')" @update:model-value="onClass" />
      <BaseSpinner v-if="loading" class="h-4 w-4" />
    </div>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <p v-if="data && !data.has_grade" class="mb-3 text-xs text-neutral-500">{{ t('timetable.subjects.noGrade') }}</p>

    <!-- Cards on a phone (below sm) -->
    <div class="space-y-2 sm:hidden">
      <p v-if="!loading && rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('timetable.subjects.empty') }}</p>
      <div v-for="r in rows" :key="r.key" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <p class="text-sm font-semibold text-neutral-800">{{ r.subject ?? t('timetable.subjects.noSubject') }}</p>
        <p class="text-xs text-neutral-500">
          {{ t('timetable.subjects.timetable') }} {{ r.timetable_hours }} · {{ t('timetable.subjects.curriculum') }} {{ r.curriculum_hours ?? '—' }} · {{ t('timetable.subjects.assigned') }} {{ r.assigned_hours ?? '—' }}
        </p>
      </div>
    </div>
    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="rows" row-key="key" :loading="loading" :empty-message="t('timetable.subjects.empty')">
        <template #cell-subject="{ row }"><span class="font-medium text-neutral-800">{{ row.subject ?? t('timetable.subjects.noSubject') }}</span></template>
        <template #cell-timetable="{ row }">{{ row.timetable_hours }}</template>
        <template #cell-curriculum="{ row }">{{ row.curriculum_hours ?? '—' }}</template>
        <template #cell-assigned="{ row }">{{ row.assigned_hours ?? '—' }}</template>
        <template #cell-diff="{ row }">
          <span v-if="diff(row) === null" class="text-neutral-400">—</span>
          <span v-else-if="diff(row) === 0" class="text-green-700">✓</span>
          <span v-else :class="diff(row)! < 0 ? 'text-red-600' : 'text-amber-700'">{{ diff(row)! > 0 ? '+' : '' }}{{ diff(row) }} h</span>
        </template>
      </DataTable>
    </div>
  </div>
</template>

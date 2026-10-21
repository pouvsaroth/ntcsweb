<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import LessonPlanModal from '@/components/admin/LessonPlanModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { lessonPlansService, type LessonOptions, type PlanRow } from '@/services/lessonPlans'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Lesson plans > Delays: planned lessons whose day has passed with nothing
 * taught yet (no teaching log done or partly done, for the plan or its
 * curriculum lesson), most late first.
 */
const { t } = useI18n()

const options = ref<LessonOptions>({ me: null, sees_all: false, classes: [], subjects: [], teachers: [] })
const filter = reactive({ staff_id: '', class_id: '' })
const rows = ref<PlanRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const teacherOptions = computed(() => [{ value: '', label: t('lessonPlans.allTeachers') }, ...options.value.teachers.map((x) => ({ value: String(x.id), label: x.name ?? '' }))])
const classOptions = computed(() => [{ value: '', label: t('lessonPlans.allClasses') }, ...options.value.classes.map((c) => ({ value: String(c.id), label: c.name }))])
const sorted = computed(() => [...rows.value].sort((a, b) => (b.days_late ?? 0) - (a.days_late ?? 0)))

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await lessonPlansService.delays({ staff_id: filter.staff_id ? Number(filter.staff_id) : undefined, class_id: filter.class_id ? Number(filter.class_id) : undefined })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('lessonPlans.loadFailed')
  } finally {
    loading.value = false
  }
}
function set(key: keyof typeof filter, value: string) {
  filter[key] = value
  void load()
}

const columns = computed(() => [
  { key: 'late', label: t('lessonPlans.delays.late') },
  { key: 'title', label: t('lessonPlans.title') },
  { key: 'date', label: t('lessonPlans.delays.planned') },
  { key: 'class', label: t('lessonPlans.class') },
  ...(options.value.sees_all ? [{ key: 'teacher', label: t('lessonPlans.teacher') }] : []),
])

const open = ref(false)
const planId = ref<number | null>(null)
function openPlan(id: number) {
  planId.value = id
  open.value = true
}

onMounted(async () => {
  options.value = await lessonPlansService.options().catch(() => options.value)
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect v-if="options.sees_all" class="w-48" :model-value="filter.staff_id" :options="teacherOptions" @update:model-value="set('staff_id', $event)" />
      <BaseSelect class="w-44" :model-value="filter.class_id" :options="classOptions" @update:model-value="set('class_id', $event)" />
      <BaseSpinner v-if="loading" class="h-4 w-4" />
    </div>
    <p class="mb-3 text-xs text-neutral-500">{{ t('lessonPlans.delays.hint') }}</p>
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="!loading && !error && rows.length === 0" variant="success" class="mb-4">{{ t('lessonPlans.delays.none') }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="space-y-2 sm:hidden">
      <button v-for="r in sorted" :key="r.id" type="button" class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]" @click="openPlan(r.id)">
        <div class="flex items-start justify-between gap-2">
          <p class="text-sm font-semibold text-neutral-800">{{ r.title }}</p>
          <span class="shrink-0 text-sm font-semibold text-red-600">{{ t('lessonPlans.delays.daysN', { count: r.days_late }) }}</span>
        </div>
        <p class="text-xs text-neutral-500">{{ formatDate(r.planned_date) }} · {{ [r.class, r.subject, options.sees_all ? r.teacher : null].filter(Boolean).join(' · ') }}</p>
      </button>
    </div>

    <div v-if="rows.length" class="hidden sm:block">
      <DataTable :columns="columns" :rows="sorted" row-key="id" :loading="loading" :empty-message="t('lessonPlans.delays.none')">
        <template #cell-late="{ row }"><span class="font-semibold text-red-600">{{ t('lessonPlans.delays.daysN', { count: row.days_late }) }}</span></template>
        <template #cell-title="{ row }">
          <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="openPlan(row.id)">{{ row.title }}</button>
          <p class="text-xs text-neutral-500">{{ [row.subject, row.curriculum_lesson].filter(Boolean).join(' · ') }}</p>
        </template>
        <template #cell-date="{ row }">{{ formatDate(row.planned_date) }}</template>
        <template #cell-class="{ row }">{{ row.class }}</template>
        <template #cell-teacher="{ row }">{{ row.teacher }}</template>
      </DataTable>
    </div>

    <LessonPlanModal v-model="open" :plan-id="planId" :options="options" @changed="load" />
  </div>
</template>

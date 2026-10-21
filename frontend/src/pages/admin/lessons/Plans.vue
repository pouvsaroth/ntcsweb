<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import LessonPlanModal from '@/components/admin/LessonPlanModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { isoDate, lessonPlansService, type LessonOptions, type PlanRow } from '@/services/lessonPlans'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/** Lesson plans > Plans: the list (filters: dates, teacher, class, status); click to open, edit, submit or review. */
const { t } = useI18n()
const auth = useAuthStore()
const canWrite = computed(() => auth.can('lesson-plans.write'))

const options = ref<LessonOptions>({ me: null, sees_all: false, classes: [], subjects: [], teachers: [] })
const today = new Date()
const filter = reactive({
  from: isoDate(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 14)),
  to: isoDate(new Date(today.getFullYear(), today.getMonth(), today.getDate() + 30)),
  staff_id: '',
  class_id: '',
  status: '',
})
const rows = ref<PlanRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const all = (label: string) => ({ value: '', label })
const teacherOptions = computed(() => [all(t('lessonPlans.allTeachers')), ...options.value.teachers.map((x) => ({ value: String(x.id), label: x.name ?? '' }))])
const classOptions = computed(() => [all(t('lessonPlans.allClasses')), ...options.value.classes.map((c) => ({ value: String(c.id), label: c.name }))])
const statusOptions = computed(() => [all(t('lessonPlans.allStatuses')), ...(['draft', 'submitted', 'approved', 'returned'] as const).map((s) => ({ value: s, label: t(`lessonPlans.status.${s}`) }))])
const statusVariant = (s: string) => (s === 'approved' ? 'success' : s === 'submitted' ? 'warning' : s === 'returned' ? 'danger' : 'neutral')

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = await lessonPlansService.plans({
      from: filter.from || undefined,
      to: filter.to || undefined,
      staff_id: filter.staff_id ? Number(filter.staff_id) : undefined,
      class_id: filter.class_id ? Number(filter.class_id) : undefined,
      status: filter.status || undefined,
    })
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
  { key: 'date', label: t('lessonPlans.date') },
  { key: 'title', label: t('lessonPlans.title') },
  { key: 'class', label: t('lessonPlans.class') },
  ...(options.value.sees_all ? [{ key: 'teacher', label: t('lessonPlans.teacher') }] : []),
  { key: 'status', label: t('lessonPlans.statusLabel') },
])

const open = ref(false)
const planId = ref<number | null>(null)
function openPlan(id: number | null) {
  planId.value = id
  open.value = true
}

onMounted(async () => {
  try {
    options.value = await lessonPlansService.options()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('lessonPlans.loadFailed')
  }
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-end gap-2">
      <BaseInput :model-value="filter.from" type="date" class="w-40" :label="t('lessonPlans.from')" @update:model-value="set('from', $event)" />
      <BaseInput :model-value="filter.to" type="date" class="w-40" :label="t('lessonPlans.to')" @update:model-value="set('to', $event)" />
      <BaseSelect v-if="options.sees_all" class="w-48" :model-value="filter.staff_id" :options="teacherOptions" @update:model-value="set('staff_id', $event)" />
      <BaseSelect class="w-44" :model-value="filter.class_id" :options="classOptions" @update:model-value="set('class_id', $event)" />
      <BaseSelect class="w-40" :model-value="filter.status" :options="statusOptions" @update:model-value="set('status', $event)" />
      <BaseSpinner v-if="loading" class="mb-2 h-4 w-4" />
      <BaseButton v-if="canWrite" class="ml-auto" :disabled="options.classes.length === 0" @click="openPlan(null)">{{ t('lessonPlans.newPlan') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="!error && canWrite && !options.sees_all && options.classes.length === 0" variant="info" class="mb-4">{{ t('lessonPlans.noClasses') }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="space-y-2 sm:hidden">
      <p v-if="!loading && rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('lessonPlans.empty') }}</p>
      <button v-for="r in rows" :key="r.id" type="button" class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]" @click="openPlan(r.id)">
        <div class="flex items-start justify-between gap-2">
          <p class="text-sm font-semibold text-neutral-800">{{ r.title }}</p>
          <BaseBadge :variant="statusVariant(r.status)">{{ t(`lessonPlans.status.${r.status}`) }}</BaseBadge>
        </div>
        <p class="text-xs text-neutral-500">{{ formatDate(r.planned_date) }} · {{ [r.class, r.subject, options.sees_all ? r.teacher : null].filter(Boolean).join(' · ') }}</p>
      </button>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="rows" row-key="id" :loading="loading" :empty-message="t('lessonPlans.empty')">
        <template #cell-date="{ row }">{{ formatDate(row.planned_date) }}</template>
        <template #cell-title="{ row }">
          <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="openPlan(row.id)">{{ row.title }}</button>
          <p class="text-xs text-neutral-500">
            {{ [row.subject, row.curriculum_lesson].filter(Boolean).join(' · ') }}<template v-if="row.files_count"> · 📎 {{ row.files_count }}</template><template v-if="row.taught"> · ✓ {{ t('lessonPlans.taught') }}</template>
          </p>
        </template>
        <template #cell-class="{ row }">{{ row.class }}</template>
        <template #cell-teacher="{ row }">{{ row.teacher }}</template>
        <template #cell-status="{ row }"><BaseBadge :variant="statusVariant(row.status)">{{ t(`lessonPlans.status.${row.status}`) }}</BaseBadge></template>
      </DataTable>
    </div>

    <LessonPlanModal v-model="open" :plan-id="planId" :options="options" @changed="load" />
  </div>
</template>

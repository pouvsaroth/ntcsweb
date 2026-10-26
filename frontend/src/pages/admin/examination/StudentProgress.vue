<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import GradebookPicker from '@/components/admin/GradebookPicker.vue'
import ProgressChart from '@/components/admin/ProgressChart.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { studentResultsService, type GradebookPick, type ProgressData, type RosterStudent } from '@/services/gradebook'
import { ApiRequestError } from '@/types/api'

/**
 * Examination > Student Progress (docs/academics/gradebook.md, 7d): a
 * student's (or a class's) results over the terms. The chart shows the
 * overall average and up to three subjects picked for comparison — more lines
 * stop being readable; every subject's numbers are in the table below.
 */
const { t } = useI18n()

const mode = ref<'student' | 'class'>('student')
const pick = ref<GradebookPick | null>(null)
const students = ref<RosterStudent[]>([])
const studentId = ref('')
const data = ref<ProgressData | null>(null)
const compare = ref<number[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const MAX_COMPARE = 3

async function onPick(p: GradebookPick) {
  pick.value = p
  students.value = p.classId ? await studentResultsService.students(p.classId).catch(() => []) : []
  studentId.value = String(students.value[0]?.id ?? '')
  await load()
}

async function load() {
  data.value = null
  const classId = pick.value?.classId
  if (!classId || (mode.value === 'student' && !studentId.value)) return
  loading.value = true
  error.value = null
  try {
    data.value = mode.value === 'student' ? await studentResultsService.studentProgress(Number(studentId.value)) : await studentResultsService.classProgress(classId)
    compare.value = data.value.subjects.slice(0, 2).map((s) => s.id)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.loadFailed')
  } finally {
    loading.value = false
  }
}
watch(mode, () => void load())

function toggle(id: number) {
  if (compare.value.includes(id)) compare.value = compare.value.filter((c) => c !== id)
  else if (compare.value.length < MAX_COMPARE) compare.value = [...compare.value, id]
}

const labels = computed(() => data.value?.points.map((p) => p.label) ?? [])
const series = computed(() => {
  const d = data.value
  if (!d) return []
  return [
    { key: 'avg', name: t('gradebook.calc.average'), values: d.points.map((p) => p.average) },
    ...compare.value
      .map((id) => d.subjects.find((s) => s.id === id))
      .filter((s): s is { id: number; title: string } => !!s)
      .map((s) => ({ key: String(s.id), name: s.title, values: d.points.map((p) => p.values[s.id] ?? null) })),
  ]
})
const studentOptions = computed(() => students.value.map((s) => ({ value: String(s.id), label: `${s.name} · ${s.code}` })))
const fmt = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${Math.round(v * 10) / 10}%`)
</script>

<template>
  <div>
    <div class="mb-3 flex rounded-lg border border-neutral-200 p-0.5 sm:inline-flex">
      <button
        v-for="m in ['student', 'class'] as const"
        :key="m"
        type="button"
        class="flex-1 rounded-md px-3 py-1 text-sm font-medium"
        :class="mode === m ? 'bg-primary-100 text-primary-800' : 'text-neutral-600 hover:bg-neutral-50'"
        @click="mode = m"
      >
        {{ t(`gradebook.progress.mode.${m}`) }}
      </button>
    </div>

    <div class="mb-4 grid gap-2 sm:grid-cols-[2fr_1fr] sm:items-end">
      <GradebookPicker no-term @change="onPick" />
      <BaseSelect v-if="mode === 'student'" v-model="studentId" :options="studentOptions" :label="t('gradebook.entry.student')" @update:model-value="load" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="!data || data.points.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('gradebook.progress.empty') }}
    </p>

    <template v-else>
      <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
        <p class="mb-1 text-sm font-semibold text-neutral-900">{{ mode === 'student' ? t('gradebook.progress.titleStudent') : t('gradebook.progress.titleClass') }}</p>
        <p class="mb-3 text-xs text-neutral-500">{{ t('gradebook.progress.compareHint', { n: MAX_COMPARE }) }}</p>
        <div class="mb-3 flex flex-wrap gap-1.5">
          <button
            v-for="s in data.subjects"
            :key="s.id"
            type="button"
            class="rounded-full border px-2.5 py-0.5 text-xs disabled:opacity-40"
            :class="compare.includes(s.id) ? 'border-neutral-800 bg-neutral-800 text-white' : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'"
            :disabled="!compare.includes(s.id) && compare.length >= MAX_COMPARE"
            @click="toggle(s.id)"
          >
            {{ s.title }}
          </button>
        </div>
        <ProgressChart :labels="labels" :series="series" :chart-label="mode === 'student' ? t('gradebook.progress.titleStudent') : t('gradebook.progress.titleClass')" />
      </div>

      <!-- The table view: every subject, every term. -->
      <div class="mt-4 overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white">
        <table class="min-w-full text-sm">
          <thead class="bg-neutral-50 text-left text-xs text-neutral-600">
            <tr>
              <th class="px-3 py-2 font-medium">{{ t('gradebook.subject') }}</th>
              <th v-for="p in data.points" :key="p.label" class="px-3 py-2 text-center font-medium whitespace-nowrap">{{ p.label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in data.subjects" :key="s.id" class="border-t border-neutral-100">
              <td class="px-3 py-1.5 text-neutral-900">{{ s.title }}</td>
              <td v-for="p in data.points" :key="p.label" class="px-3 py-1.5 text-center tabular-nums text-neutral-800">{{ fmt(p.values[s.id]) }}</td>
            </tr>
            <tr class="border-t border-neutral-200 font-semibold">
              <td class="px-3 py-1.5">{{ t('gradebook.calc.average') }}</td>
              <td v-for="p in data.points" :key="p.label" class="px-3 py-1.5 text-center tabular-nums">{{ fmt(p.average) }}</td>
            </tr>
            <tr v-if="mode === 'class'" class="border-t border-neutral-100 text-neutral-600">
              <td class="px-3 py-1.5">{{ t('gradebook.progress.passRate') }}</td>
              <td v-for="p in data.points" :key="p.label" class="px-3 py-1.5 text-center tabular-nums">{{ fmt(p.pass_rate) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

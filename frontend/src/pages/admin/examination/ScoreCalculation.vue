<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import GradebookPicker from '@/components/admin/GradebookPicker.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { gradebookService, type ClassResults, type GradebookPick } from '@/services/gradebook'
import { ApiRequestError } from '@/types/api'

/**
 * Examination > Score Calculation (docs/academics/gradebook.md, 7b): one
 * class's term — each subject's result, the average of the subjects, the
 * overall score on the setup's scale, band, rank and pass. Calculated by the
 * server from the entries; shown as scores or as percents.
 */
const { t } = useI18n()

const data = ref<ClassResults | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const show = ref<'score' | 'percent'>('score')

async function onPick(p: GradebookPick) {
  data.value = null
  if (!p.termId || !p.classId) return
  loading.value = true
  error.value = null
  try {
    data.value = await gradebookService.results(p.termId, p.classId)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.loadFailed')
  } finally {
    loading.value = false
  }
}

type Cell = ClassResults['students'][number]['cells'][string]
const cellText = (c: Cell) => (!c || c.percent === null ? '—' : show.value === 'score' ? String(c.score ?? '—') : `${c.percent}%`)
const totalText = (r: ClassResults['students'][number]) => (r.average === null ? '—' : show.value === 'score' ? String(r.score ?? '—') : `${r.average}%`)
const sorted = computed(() => [...(data.value?.students ?? [])].sort((a, b) => (a.rank ?? 9999) - (b.rank ?? 9999) || a.name.localeCompare(b.name)))
const passedCount = computed(() => data.value?.students.filter((s) => s.passed === true).length ?? 0)
const printPage = () => window.print()
</script>

<template>
  <div>
    <GradebookPicker class="mb-4 print:hidden" @change="onPick" />
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <p v-else-if="!data" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('gradebook.calc.pickFirst') }}
    </p>

    <template v-else>
      <div class="mb-3 flex flex-wrap items-center gap-3 text-sm">
        <h2 class="font-semibold text-neutral-900">{{ data.class.name }} · {{ data.term.name }}</h2>
        <span v-if="data.scheme" class="text-neutral-600">{{ data.scheme.name }} · {{ t('gradebook.entry.outOf', { max: data.scheme.max_score }) }}</span>
        <span class="text-neutral-600">{{ t('gradebook.calc.passed', { n: passedCount, total: data.students.length }) }}</span>
        <div class="ml-auto flex items-center gap-2 print:hidden">
          <div class="flex rounded-lg border border-neutral-200 p-0.5">
            <button
              v-for="m in ['score', 'percent'] as const"
              :key="m"
              type="button"
              class="rounded-md px-3 py-1 text-sm font-medium"
              :class="show === m ? 'bg-primary-100 text-primary-800' : 'text-neutral-600 hover:bg-neutral-50'"
              @click="show = m"
            >
              {{ t(`gradebook.calc.show.${m}`) }}
            </button>
          </div>
          <BaseButton variant="outline" @click="printPage">{{ t('gradebook.print') }}</BaseButton>
        </div>
      </div>
      <BaseAlert v-if="!data.scheme" variant="warning" class="mb-4">{{ t('gradebook.entry.noScheme') }}</BaseAlert>

      <p v-if="data.students.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('gradebook.entry.noStudents') }}
      </p>

      <template v-else>
        <!-- Phone: one card per student -->
        <div class="space-y-2 sm:hidden print:hidden">
          <div v-for="s in sorted" :key="s.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate font-semibold text-neutral-900">{{ s.rank ? `${s.rank}. ` : '' }}{{ s.name }}</p>
                <p class="text-xs text-neutral-400">{{ s.code }}</p>
              </div>
              <div class="text-right">
                <p class="font-semibold" :class="s.passed === false ? 'text-red-600' : 'text-neutral-900'">{{ totalText(s) }} {{ s.band ?? '' }}</p>
                <p v-if="!s.complete" class="text-xs text-amber-700">{{ t('gradebook.calc.incomplete') }}</p>
              </div>
            </div>
            <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
              <template v-for="sub in data.subjects" :key="sub.id">
                <dt class="truncate text-neutral-500">{{ sub.title }}</dt>
                <dd class="text-right text-neutral-800">{{ cellText(s.cells[sub.id]) }} {{ s.cells[sub.id]?.band ?? '' }}</dd>
              </template>
            </dl>
          </div>
        </div>

        <!-- Tablet / desktop / print: one row per student -->
        <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block print:block">
          <table class="min-w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs text-neutral-600">
              <tr>
                <th class="px-3 py-2 text-center font-medium">{{ t('gradebook.entry.rank') }}</th>
                <th class="sticky left-0 bg-neutral-50 px-3 py-2 font-medium">{{ t('gradebook.entry.student') }}</th>
                <th v-for="sub in data.subjects" :key="sub.id" class="px-2 py-2 text-center font-medium whitespace-nowrap">{{ sub.title }}</th>
                <th class="px-2 py-2 text-center font-medium">{{ show === 'score' ? t('gradebook.calc.total') : t('gradebook.calc.average') }}</th>
                <th class="px-2 py-2 text-center font-medium">{{ t('gradebook.entry.band') }}</th>
                <th class="px-2 py-2 text-center font-medium">{{ t('gradebook.calc.failed') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in sorted" :key="s.id" class="border-t border-neutral-100">
                <td class="px-3 py-1.5 text-center text-neutral-700">{{ s.rank ?? '—' }}</td>
                <td class="sticky left-0 bg-white px-3 py-1.5 whitespace-nowrap">
                  <span class="font-medium text-neutral-900">{{ s.name }}</span>
                  <span v-if="!s.complete" class="ml-1 text-xs text-amber-700">· {{ t('gradebook.calc.incomplete') }}</span>
                </td>
                <td v-for="sub in data.subjects" :key="sub.id" class="px-2 py-1.5 text-center whitespace-nowrap" :class="s.cells[sub.id]?.passed === false ? 'text-red-600' : 'text-neutral-800'">
                  {{ cellText(s.cells[sub.id]) }}
                </td>
                <td class="px-2 py-1.5 text-center font-semibold whitespace-nowrap" :class="s.passed === false ? 'text-red-600' : 'text-neutral-900'">{{ totalText(s) }}</td>
                <td class="px-2 py-1.5 text-center font-semibold">{{ s.band ?? '—' }}</td>
                <td class="px-2 py-1.5 text-center text-neutral-700">{{ s.failed_subjects || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mt-2 text-xs text-neutral-500">{{ t('gradebook.calc.note', { pass: data.pass_mark }) }}</p>
      </template>
    </template>
  </div>
</template>

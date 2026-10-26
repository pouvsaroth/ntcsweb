<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { Transcript, TranscriptYear } from '@/services/gradebook'

/**
 * A transcript, year by year (docs/academics/gradebook.md, 7d): each subject's
 * result per term and for the year, the averages, band and rank. Used by the
 * Academic Transcript tab and by the student's own My Scores page. Desktop:
 * one table per year; phone: one card per term.
 */
const props = defineProps<{ transcript: Transcript }>()
const { t } = useI18n()

const fmt = (v: number | null | undefined) => (v === null || v === undefined ? '—' : String(Math.round(v * 100) / 100))
const subjectsOf = (y: TranscriptYear) => {
  const seen = new Map<number, string>()
  for (const term of y.terms) for (const s of term.subjects) if (!seen.has(s.id)) seen.set(s.id, s.title)
  return [...seen.entries()].map(([id, title]) => ({ id, title }))
}
const cell = (y: TranscriptYear, termIndex: number, subjectId: number) => y.terms[termIndex]?.subjects.find((s) => s.id === subjectId) ?? null
const years = computed(() => props.transcript.years)
const result = (p: boolean | null) => (p === null ? '—' : p ? t('gradebook.transcript.pass') : t('gradebook.transcript.fail'))
</script>

<template>
  <p v-if="years.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
    {{ transcript.approved_only ? t('gradebook.transcript.noApproved') : t('gradebook.transcript.none') }}
  </p>

  <div v-for="y in years" :key="y.academic_year.id" class="mb-6">
    <h3 class="mb-2 text-sm font-semibold text-neutral-900">
      {{ y.academic_year.name }} · {{ y.class.name }}<span v-if="y.class.grade_level" class="font-normal text-neutral-500"> · {{ y.class.grade_level }}</span>
    </h3>

    <!-- Desktop: subjects × terms + the year -->
    <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
      <table class="min-w-full text-sm">
        <thead class="bg-neutral-50 text-left text-xs text-neutral-600">
          <tr>
            <th class="px-3 py-2 font-medium">{{ t('gradebook.subject') }}</th>
            <th v-for="term in y.terms" :key="term.term.id" class="px-3 py-2 text-center font-medium">
              {{ term.term.name }}<span v-if="term.scheme" class="block font-normal text-neutral-400">/{{ term.scheme.max_score }}</span>
            </th>
            <th class="px-3 py-2 text-center font-medium">{{ t('gradebook.transcript.year') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in subjectsOf(y)" :key="s.id" class="border-t border-neutral-100">
            <td class="px-3 py-1.5 text-neutral-900">{{ s.title }}</td>
            <td v-for="(term, ti) in y.terms" :key="term.term.id" class="px-3 py-1.5 text-center whitespace-nowrap" :class="cell(y, ti, s.id)?.passed === false ? 'text-red-600' : 'text-neutral-800'">
              <template v-if="cell(y, ti, s.id)">
                {{ fmt(cell(y, ti, s.id)!.score) }}<span v-if="cell(y, ti, s.id)!.band" class="ml-1 text-xs font-semibold">{{ cell(y, ti, s.id)!.band }}</span>
                <span v-if="cell(y, ti, s.id)!.status !== 'approved'" class="ml-1 text-xs text-amber-700">*</span>
              </template>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-3 py-1.5 text-center text-neutral-800">{{ fmt(y.year.subjects.find((ys) => ys.id === s.id)?.score) }}</td>
          </tr>
          <tr class="border-t border-neutral-200 bg-amber-50/60 font-semibold">
            <td class="px-3 py-1.5">{{ t('gradebook.calc.average') }}</td>
            <td v-for="term in y.terms" :key="term.term.id" class="px-3 py-1.5 text-center whitespace-nowrap" :class="term.passed === false ? 'text-red-600' : ''">
              {{ fmt(term.score) }}<span v-if="term.band" class="ml-1 text-xs">{{ term.band }}</span>
            </td>
            <td class="px-3 py-1.5 text-center" :class="y.year.passed === false ? 'text-red-600' : ''">
              {{ fmt(y.year.score) }}<span v-if="y.year.band" class="ml-1 text-xs">{{ y.year.band }}</span>
            </td>
          </tr>
          <tr class="border-t border-neutral-100 text-neutral-600">
            <td class="px-3 py-1.5">{{ t('gradebook.entry.rank') }}</td>
            <td v-for="term in y.terms" :key="term.term.id" class="px-3 py-1.5 text-center">{{ term.rank ? `${term.rank} / ${term.class_size}` : '—' }}</td>
            <td class="px-3 py-1.5 text-center">{{ result(y.year.passed) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Phone: one card per term -->
    <div class="space-y-2 sm:hidden">
      <div v-for="term in y.terms" :key="term.term.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
        <div class="flex items-start justify-between gap-2">
          <p class="font-semibold text-neutral-900">{{ term.term.name }}</p>
          <p class="text-right">
            <span class="font-semibold" :class="term.passed === false ? 'text-red-600' : 'text-neutral-900'">{{ fmt(term.score) }} {{ term.band ?? '' }}</span>
            <span v-if="term.rank" class="block text-xs text-neutral-500">{{ t('gradebook.entry.rank') }} {{ term.rank }} / {{ term.class_size }}</span>
          </p>
        </div>
        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
          <template v-for="s in term.subjects" :key="s.id">
            <dt class="truncate text-neutral-500">{{ s.title }}</dt>
            <dd class="text-right" :class="s.passed === false ? 'text-red-600' : 'text-neutral-800'">{{ fmt(s.score) }} {{ s.band ?? '' }}</dd>
          </template>
        </dl>
      </div>
      <div class="rounded-[--radius-card] border border-amber-200 bg-amber-50 p-3 text-sm">
        <span class="font-semibold">{{ t('gradebook.transcript.year') }}:</span> {{ fmt(y.year.score) }} {{ y.year.band ?? '' }} · {{ result(y.year.passed) }}
      </div>
    </div>
  </div>
  <p v-if="!transcript.approved_only && years.length" class="text-xs text-neutral-500">{{ t('gradebook.transcript.notApprovedNote') }}</p>
</template>

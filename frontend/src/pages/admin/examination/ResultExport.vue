<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import GradebookPicker from '@/components/admin/GradebookPicker.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { studentResultsService, type GradebookPick, type RosterStudent } from '@/services/gradebook'
import { ApiRequestError } from '@/types/api'

/**
 * Examination > Result Export (docs/academics/gradebook.md, 7d): for a class
 * and term — the score sheet (Excel: every subject, then one sheet per
 * subject with every score) and report cards (PDF, approved results; the
 * whole class or one student).
 */
const { t } = useI18n()

const pick = ref<GradebookPick | null>(null)
const students = ref<RosterStudent[]>([])
const loading = ref(true)
const busy = ref<string | null>(null)
const error = ref<string | null>(null)

const ready = computed(() => !!pick.value?.classId && !!pick.value.termId)
const base = computed(() => `${pick.value?.klass?.name ?? 'class'}`.replace(/[^\p{L}\p{N}]+/gu, '-'))

async function onPick(p: GradebookPick) {
  pick.value = p
  loading.value = true
  students.value = p.classId ? await studentResultsService.students(p.classId).catch(() => []) : []
  loading.value = false
}

async function run(key: string, job: () => Promise<void>) {
  busy.value = key
  error.value = null
  try {
    await job()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.loadFailed')
  } finally {
    busy.value = null
  }
}

const scoreSheet = () => run('sheet', () => studentResultsService.downloadScoreSheet(pick.value!.classId!, pick.value!.termId!, `scores-${base.value}.xlsx`))
const allCards = () => run('cards', () => studentResultsService.downloadReportCards(pick.value!.classId!, pick.value!.termId!, `report-cards-${base.value}.pdf`))
const oneCard = (s: RosterStudent) =>
  run(`card-${s.id}`, () => studentResultsService.downloadReportCards(pick.value!.classId!, pick.value!.termId!, `report-card-${s.code}.pdf`, s.id))
</script>

<template>
  <div>
    <GradebookPicker class="mb-4" @change="onPick" />
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="grid gap-3 md:grid-cols-2">
      <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
        <p class="font-semibold text-neutral-900">{{ t('gradebook.export.sheetTitle') }}</p>
        <p class="mt-1 text-sm text-neutral-600">{{ t('gradebook.export.sheetHint') }}</p>
        <BaseButton class="mt-3" :disabled="!ready" :loading="busy === 'sheet'" @click="scoreSheet">{{ t('gradebook.export.sheetButton') }}</BaseButton>
      </div>
      <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
        <p class="font-semibold text-neutral-900">{{ t('gradebook.export.cardsTitle') }}</p>
        <p class="mt-1 text-sm text-neutral-600">{{ t('gradebook.export.cardsHint') }}</p>
        <BaseButton class="mt-3" :disabled="!ready || students.length === 0" :loading="busy === 'cards'" @click="allCards">{{ t('gradebook.export.cardsButton', { n: students.length }) }}</BaseButton>
      </div>
    </div>

    <div class="mt-4 rounded-[--radius-card] border border-neutral-200 bg-white">
      <p class="border-b border-neutral-100 px-4 py-2 text-sm font-semibold text-neutral-900">{{ t('gradebook.export.oneCard') }}</p>
      <div v-if="loading" class="flex justify-center py-6"><BaseSpinner /></div>
      <p v-else-if="students.length === 0" class="px-4 py-6 text-center text-sm text-neutral-500">{{ t('gradebook.entry.noStudents') }}</p>
      <ul v-else class="divide-y divide-neutral-100">
        <li v-for="s in students" :key="s.id" class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
          <span class="min-w-0 truncate"><span class="font-medium text-neutral-900">{{ s.name }}</span> <span class="text-neutral-400">· {{ s.code }}</span></span>
          <button type="button" class="shrink-0 font-medium text-secondary-700 hover:underline disabled:opacity-50" :disabled="!ready || busy !== null" @click="oneCard(s)">
            {{ busy === `card-${s.id}` ? '…' : t('gradebook.export.pdf') }}
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

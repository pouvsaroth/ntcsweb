<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { myScoresService, type ExamMention, type MyScore } from '@/services/myScores'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * The student's Score card (Dashboard.vue) — one card per exam score
 * entered for them: book, exam date, score, mention, and "Make-up exam" when
 * that score is from a retake.
 */
const { t } = useI18n()

const scores = ref<MyScore[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const mentionVariant: Record<ExamMention, 'success' | 'primary' | 'neutral' | 'danger'> = {
  excellent: 'success',
  very_good: 'primary',
  good: 'neutral',
  fail: 'danger',
}

function formatScore(score: number): string {
  return Number.isInteger(score) ? String(score) : score.toFixed(2).replace(/0$/, '')
}

onMounted(async () => {
  try {
    scores.value = await myScoresService.list()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.myScores.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.myScores.title') }}</h1>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <p
      v-else-if="!error && scores.length === 0"
      class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500"
    >
      {{ t('admin.myScores.emptyMessage') }}
    </p>

    <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <div
        v-for="item in scores"
        :key="item.exam_application_id"
        class="flex flex-col rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-xs text-neutral-500">{{ t('admin.myScores.book') }}</p>
            <p class="font-semibold text-neutral-900">{{ item.book ?? item.course_package ?? '—' }}</p>
          </div>
          <BaseBadge v-if="item.is_make_up" variant="warning">{{ t('admin.myScores.makeUpExam') }}</BaseBadge>
        </div>

        <div class="mt-4 flex items-end justify-between gap-3">
          <div>
            <p class="text-xs text-neutral-500">{{ t('admin.myScores.score') }}</p>
            <p class="text-3xl font-bold leading-none" :class="item.mention === 'fail' ? 'text-danger-600' : 'text-neutral-900'">
              {{ formatScore(item.score) }}
            </p>
          </div>
          <div class="text-right">
            <p class="text-xs text-neutral-500">{{ t('admin.myScores.mention') }}</p>
            <BaseBadge :variant="mentionVariant[item.mention]" class="mt-1">{{ t(`admin.myScores.mentions.${item.mention}`) }}</BaseBadge>
          </div>
        </div>

        <p class="mt-4 border-t border-neutral-100 pt-3 text-sm text-neutral-600">
          <span class="text-neutral-500">{{ t('admin.myScores.examDate') }}:</span> {{ formatDate(item.exam_date) }}
        </p>
      </div>
    </div>
  </div>
</template>

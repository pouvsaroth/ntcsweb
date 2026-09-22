<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { examScoresService, type ExamScoreEntry, type ExamScoreEntryInput, type ExamScoreFilters } from '@/services/examScores'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * "Add Score" popup: every approved application still missing a score
 * (scoped by whatever Course/Class/Book the Grades tab currently has
 * selected — see `filters`), each with its own Score/Remark input, saved
 * together in one batch. A plain array of drafts (not a map keyed by id) so
 * there's no separate lookup that could ever drift out of sync with what's
 * rendered.
 */
const props = defineProps<{ modelValue: boolean; filters: ExamScoreFilters }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

interface Draft {
  entry: ExamScoreEntry
  score: string
  remark: string
  /** Check alongside a failing score to generate a scoreable retake for this student — see ExamScoreEntryInput's `make_up`. */
  makeUp: boolean
}

const drafts = ref<Draft[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const saving = ref(false)
const saveError = ref<string | null>(null)

async function load() {
  loading.value = true
  loadError.value = null
  drafts.value = []

  try {
    const entries = await examScoresService.list({ ...props.filters, scored: false })
    drafts.value = entries.map((entry) => ({ entry, score: '', remark: '', makeUp: false }))
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.grades.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    saveError.value = null
    if (open) void load()
  },
)

async function save() {
  saving.value = true
  saveError.value = null

  try {
    const entries: ExamScoreEntryInput[] = drafts.value
      .filter((draft) => String(draft.score).trim() !== '')
      .map((draft) => ({
        exam_application_id: draft.entry.exam_application_id,
        score: Number(draft.score),
        remark: String(draft.remark).trim() || null,
        make_up: draft.makeUp,
      }))

    if (entries.length === 0) return

    await examScoresService.record(entries)
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    // eslint-disable-next-line no-console
    console.error('Failed to save exam scores', error)
    saveError.value = error instanceof ApiRequestError ? error.message : t('admin.grades.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.grades.addScore')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <BaseAlert v-if="loadError" variant="danger" class="mb-4">{{ loadError }}</BaseAlert>
    <BaseAlert v-if="saveError" variant="danger" class="mb-4">{{ saveError }}</BaseAlert>

    <BaseSpinner v-if="loading" class="mx-auto" />

    <p v-else-if="drafts.length === 0" class="py-8 text-center text-sm text-neutral-400">{{ t('admin.grades.emptyMessage') }}</p>

    <div v-else class="max-h-[60vh] divide-y divide-neutral-100 overflow-y-auto rounded-lg border border-neutral-200">
      <div v-for="draft in drafts" :key="draft.entry.exam_application_id" class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:gap-4">
        <div class="sm:w-56">
          <p class="font-medium text-neutral-800">{{ draft.entry.student?.name ?? '—' }}</p>
          <p class="text-xs text-neutral-500">
            {{ draft.entry.enrollment_code ?? '—' }} · {{ draft.entry.course_package?.name ?? '—' }}
            <span v-if="draft.entry.exam_date"> · {{ formatDate(draft.entry.exam_date) }}</span>
          </p>
        </div>

        <input
          v-model="draft.score"
          type="number"
          min="0"
          max="100"
          step="0.01"
          :placeholder="t('admin.grades.scorePlaceholder')"
          class="w-28 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />

        <input
          v-model="draft.remark"
          type="text"
          :placeholder="t('admin.grades.remarkPlaceholder')"
          class="flex-1 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />

        <label class="flex shrink-0 items-center gap-1.5 text-sm text-neutral-700">
          <input v-model="draft.makeUp" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.grades.makeUpExam') }}
        </label>
      </div>
    </div>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :disabled="drafts.length === 0" :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

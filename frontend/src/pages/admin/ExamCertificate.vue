<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ExaminationTabs from '@/components/admin/ExaminationTabs.vue'
import PhotoReceivedModal from '@/components/admin/PhotoReceivedModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { examApplicationsService, type ExamApplication } from '@/services/examApplications'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/utils/date'

/**
 * Examination → Certificate: every student who passed the exam (score ≥ 85,
 * from the Grades tab — see backend ExamApplication::scopePassed()). When a
 * student hands in the photo for their certificate, staff tick them and
 * click "Received Photo" to record the date and a remark.
 */
const { t } = useI18n()
const auth = useAuthStore()

const canUpdate = computed(() => auth.can('exam-applications.update'))

/** '' = everyone; otherwise whether their photo has been received yet. */
const photoFilter = ref<'' | 'yes' | 'no'>('')
const photoFilterOptions = computed(() => [
  { value: '', label: t('admin.examCertificate.filterAll') },
  { value: 'no', label: t('admin.examCertificate.filterNotReceived') },
  { value: 'yes', label: t('admin.examCertificate.filterReceived') },
])

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<ExamApplication>((query) =>
  examApplicationsService.list(query, { certificate: true, photoReceived: photoFilter.value || undefined }),
)

function onPhotoFilterChange(value: string) {
  photoFilter.value = value as '' | 'yes' | 'no'
  selectedIds.value = []
  setPage(1)
}

const selectedIds = ref<number[]>([])

const columns = computed(() => [
  { key: 'student_code', label: t('admin.exams.columnStudentCode') },
  { key: 'full_name', label: t('admin.exams.columnFullName') },
  { key: 'book', label: t('admin.exams.columnBook') },
  { key: 'exam_date', label: t('admin.exams.columnExamDate') },
  { key: 'score', label: t('admin.examCertificate.columnScore'), align: 'text-right' },
  { key: 'mention', label: t('admin.examCertificate.columnMention') },
  { key: 'photo_received', label: t('admin.examCertificate.columnPhotoReceived') },
])

const mentionVariant: Record<string, 'success' | 'warning' | 'neutral'> = {
  excellent: 'success',
  very_good: 'success',
  good: 'warning',
}

const modalOpen = ref(false)

async function onSaved() {
  selectedIds.value = []
  await fetch()
}

onMounted(() => void fetch())
</script>

<template>
  <div>
    <ExaminationTabs />

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.examCertificate.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('admin.examCertificate.subtitle') }}</p>
      </div>
      <BaseButton v-if="canUpdate" :disabled="selectedIds.length === 0" @click="modalOpen = true">
        {{ t('admin.examCertificate.receivedPhoto') }}<template v-if="selectedIds.length"> ({{ selectedIds.length }})</template>
      </BaseButton>
    </div>

    <div class="mb-4 max-w-xs">
      <BaseSelect :model-value="photoFilter" :options="photoFilterOptions" @update:model-value="onPhotoFilterChange" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :selectable="canUpdate"
      :selected="selectedIds"
      :empty-message="t('admin.examCertificate.emptyMessage')"
      @update:selected="selectedIds = $event as number[]"
    >
      <template #cell-student_code="{ row }">{{ (row as ExamApplication).student.student_code }}</template>
      <template #cell-full_name="{ row }">{{ (row as ExamApplication).student.name }}</template>
      <template #cell-book="{ row }">{{ (row as ExamApplication).book?.title ?? (row as ExamApplication).enrollment.course_package?.name ?? '—' }}</template>
      <template #cell-exam_date="{ row }">{{ formatDate((row as ExamApplication).exam_date) }}</template>
      <template #cell-score="{ row }">
        <span class="font-semibold text-neutral-900">{{ (row as ExamApplication).score?.score ?? '—' }}</span>
      </template>
      <template #cell-mention="{ row }">
        <BaseBadge v-if="(row as ExamApplication).score" :variant="mentionVariant[(row as ExamApplication).score!.mention] ?? 'neutral'">
          {{ t(`admin.myScores.mentions.${(row as ExamApplication).score!.mention}`) }}
        </BaseBadge>
      </template>
      <template #cell-photo_received="{ row }">
        <template v-if="(row as ExamApplication).photo_received_date">
          <BaseBadge variant="success">✓ {{ formatDate((row as ExamApplication).photo_received_date) }}</BaseBadge>
          <p v-if="(row as ExamApplication).photo_received_remark" class="mt-1 max-w-56 text-xs text-neutral-500">
            {{ (row as ExamApplication).photo_received_remark }}
          </p>
        </template>
        <span v-else class="text-sm text-neutral-400">{{ t('admin.examCertificate.notReceived') }}</span>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <PhotoReceivedModal v-model="modalOpen" :ids="selectedIds" @saved="onSaved" />
  </div>
</template>

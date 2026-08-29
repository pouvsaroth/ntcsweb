<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { studentImportsService, type StudentImport, type StudentImportStatus } from '@/services/studentImports'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<StudentImport>((query) =>
  studentImportsService.list(query),
)

const columns = [
  { key: 'original_filename', label: t('admin.studentImports.columnFile') },
  { key: 'status', label: t('admin.studentImports.columnStatus') },
  { key: 'total_rows', label: t('admin.studentImports.columnRows') },
  { key: 'imported_count', label: t('admin.studentImports.columnImported') },
  { key: 'skipped_count', label: t('admin.studentImports.columnSkipped') },
  { key: 'created_at', label: t('admin.studentImports.columnUploadedAt') },
  { key: 'errors', label: t('admin.studentImports.columnErrors'), align: 'text-right' },
]

const badgeVariant: Record<StudentImportStatus, 'neutral' | 'primary' | 'success' | 'danger'> = {
  pending: 'neutral',
  processing: 'primary',
  completed: 'success',
  failed: 'danger',
}

const fileInput = ref<HTMLInputElement | null>(null)
const selectedFile = ref<File | null>(null)
const uploading = ref(false)
const uploadError = ref<string | null>(null)

const errorsModalOpen = ref(false)
const errorsModalImport = ref<StudentImport | null>(null)

function onFileChosen(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null
  selectedFile.value = file
  uploadError.value = null
}

async function upload() {
  if (!selectedFile.value) return

  uploading.value = true
  uploadError.value = null

  try {
    await studentImportsService.upload(selectedFile.value)
    selectedFile.value = null
    if (fileInput.value) fileInput.value.value = ''
    await fetch()
  } catch (e) {
    uploadError.value = e instanceof ApiRequestError ? e.message : t('admin.studentImports.uploadFailed')
  } finally {
    uploading.value = false
  }
}

function showErrors(row: StudentImport) {
  errorsModalImport.value = row
  errorsModalOpen.value = true
}

const hasUnfinishedImports = computed(() =>
  items.value.some((row) => row.status === 'pending' || row.status === 'processing'),
)

let pollHandle: ReturnType<typeof setInterval> | undefined

onMounted(async () => {
  await fetch()
  pollHandle = setInterval(() => {
    if (hasUnfinishedImports.value) void fetch()
  }, 4000)
})

onUnmounted(() => clearInterval(pollHandle))
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.studentImports.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.studentImports.pageSubtitle') }}</p>
    </div>

    <div class="mb-6 rounded-[--radius-card] border border-neutral-200 bg-white p-6">
      <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.studentImports.uploadCard') }}</h2>
      <p class="mt-1 text-xs text-neutral-500">{{ t('admin.studentImports.fileHint') }}</p>

      <div class="mt-4 flex flex-wrap items-center gap-3">
        <input
          ref="fileInput"
          type="file"
          accept=".csv,text/csv"
          class="block text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-neutral-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-neutral-700 hover:file:bg-neutral-200"
          @change="onFileChosen"
        />
        <BaseButton :disabled="!selectedFile" :loading="uploading" @click="upload">
          {{ uploading ? t('admin.studentImports.uploading') : t('admin.studentImports.upload') }}
        </BaseButton>
      </div>

      <BaseAlert v-if="uploadError" variant="danger" class="mt-3">{{ uploadError }}</BaseAlert>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :empty-message="t('admin.studentImports.emptyMessage')"
    >
      <template #cell-status="{ row }">
        <BaseBadge :variant="badgeVariant[row.status as StudentImportStatus]">
          {{ t(`admin.studentImports.status.${row.status}`) }}
        </BaseBadge>
      </template>
      <template #cell-created_at="{ row }">
        {{ new Date(row.created_at).toLocaleString() }}
      </template>
      <template #cell-errors="{ row }">
        <button
          v-if="row.errors && row.errors.length > 0"
          type="button"
          class="text-sm font-medium text-secondary-600 hover:text-secondary-700"
          @click="showErrors(row)"
        >
          {{ t('admin.studentImports.viewErrors', { count: row.errors.length }) }}
        </button>
        <span v-else class="text-neutral-400">—</span>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />

    <BaseModal
      v-model="errorsModalOpen"
      :title="t('admin.studentImports.errorsTitle', { file: errorsModalImport?.original_filename ?? '' })"
      size="lg"
    >
      <ul class="max-h-96 space-y-2 overflow-y-auto text-sm">
        <li v-for="(rowError, index) in errorsModalImport?.errors ?? []" :key="index" class="rounded-lg bg-neutral-50 px-3 py-2">
          {{ t('admin.studentImports.errorRow', { row: rowError.row, message: rowError.message }) }}
        </li>
      </ul>
    </BaseModal>
  </div>
</template>

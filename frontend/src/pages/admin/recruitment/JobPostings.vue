<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import JobPostingFormModal from '@/components/admin/JobPostingFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { JOB_POSTING_CHANNELS, jobPositionsService, jobPostingsService, type JobPosition, type JobPosting } from '@/services/jobPositions'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Recruitment > Job postings — where each job is advertised. An
 * active Website posting puts its (open) job on the public Careers page.
 * Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canCreate = computed(() => auth.can('recruitment.create'))
const canUpdate = computed(() => auth.can('recruitment.update'))
const canDelete = computed(() => auth.can('recruitment.delete'))

const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<JobPosting>((query) => jobPostingsService.list(query))

const jobs = ref<JobPosition[]>([])
const jobFilter = ref('')
const channelFilter = ref('')

const jobFilterOptions = computed(() => [
  { value: '', label: t('admin.recruitment.postings.allJobs') },
  ...jobs.value.map((job) => ({ value: String(job.id), label: `${job.reference} · ${job.title}` })),
])
const channelFilterOptions = computed(() => [
  { value: '', label: t('admin.recruitment.postings.allChannels') },
  ...JOB_POSTING_CHANNELS.map((channel) => ({ value: channel, label: t(`admin.recruitment.channels.${channel}`) })),
])

function onJobFilter(value: string) {
  jobFilter.value = value
  setFilter('job_position_id', value || undefined)
}

function onChannelFilter(value: string) {
  channelFilter.value = value
  setFilter('channel', value || undefined)
}

/** Expired, not yet started, or switched off — not currently advertising. */
function isLive(posting: JobPosting): boolean {
  const now = new Date()
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  return posting.is_active && posting.posted_on <= today && (!posting.expires_on || posting.expires_on >= today)
}

const columns = computed(() => [
  { key: 'job', label: t('admin.recruitment.postings.job') },
  { key: 'channel', label: t('admin.recruitment.postings.channel') },
  { key: 'posted_on', label: t('admin.recruitment.postings.postedOn') },
  { key: 'expires_on', label: t('admin.recruitment.postings.expiresOn') },
  { key: 'state', label: t('admin.recruitment.positions.status') },
  { key: 'actions', label: t('admin.recruitment.manpower.actions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editing = ref<JobPosting | null>(null)
const actionError = ref<string | null>(null)

function openCreate() {
  editing.value = null
  modalOpen.value = true
}

function openEdit(posting: JobPosting) {
  editing.value = posting
  modalOpen.value = true
}

async function remove(posting: JobPosting) {
  if (!(await confirmDialog.confirm({ message: t('admin.recruitment.postings.deleteConfirm'), danger: true }))) return
  actionError.value = null
  try {
    await jobPostingsService.remove(posting.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.manpower.deleteFailed')
  }
}

onMounted(async () => {
  void fetch()
  jobs.value = await jobPositionsService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-full max-w-xs" :model-value="jobFilter" :options="jobFilterOptions" @update:model-value="onJobFilter" />
      <BaseSelect class="w-44" :model-value="channelFilter" :options="channelFilterOptions" @update:model-value="onChannelFilter" />
      <BaseButton v-if="canCreate" class="ml-auto" :disabled="jobs.length === 0" @click="openCreate">{{ t('admin.recruitment.postings.add') }}</BaseButton>
    </div>
    <p v-if="canCreate && !loading && jobs.length === 0" class="mb-4 text-sm text-neutral-500">{{ t('admin.recruitment.postings.noJobsHint') }}</p>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.recruitment.postings.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-800">{{ row.job_position?.title ?? '—' }}</p>
              <p class="text-xs text-neutral-500">{{ t(`admin.recruitment.channels.${row.channel}`) }}</p>
            </div>
            <BaseBadge :variant="isLive(row) ? 'success' : 'neutral'" class="shrink-0">
              {{ isLive(row) ? t('admin.recruitment.postings.live') : t('admin.recruitment.postings.notLive') }}
            </BaseBadge>
          </div>
          <p class="mt-2 text-xs text-neutral-500">{{ formatDate(row.posted_on) }} → {{ formatDate(row.expires_on) }}</p>
          <a v-if="row.url" :href="row.url" target="_blank" rel="noopener" class="block truncate text-xs text-primary-700 hover:underline">{{ row.url }}</a>
          <div v-if="canUpdate || canDelete" class="mt-2 flex justify-end gap-3">
            <EditIconButton v-if="canUpdate" @click="openEdit(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.recruitment.manpower.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.recruitment.postings.empty')">
        <template #cell-job="{ row }">
          <p class="font-medium text-neutral-800">{{ row.job_position?.title ?? '—' }}</p>
          <p class="text-xs text-neutral-500">{{ row.job_position?.reference }}</p>
        </template>
        <template #cell-channel="{ row }">
          <p>{{ t(`admin.recruitment.channels.${row.channel}`) }}</p>
          <a v-if="row.url" :href="row.url" target="_blank" rel="noopener" class="block max-w-56 truncate text-xs text-primary-700 hover:underline">{{ row.url }}</a>
        </template>
        <template #cell-posted_on="{ row }">{{ formatDate(row.posted_on) }}</template>
        <template #cell-expires_on="{ row }">{{ formatDate(row.expires_on) }}</template>
        <template #cell-state="{ row }">
          <BaseBadge :variant="isLive(row as JobPosting) ? 'success' : 'neutral'">
            {{ isLive(row as JobPosting) ? t('admin.recruitment.postings.live') : t('admin.recruitment.postings.notLive') }}
          </BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton v-if="canUpdate" @click="openEdit(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
              {{ t('admin.recruitment.manpower.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <JobPostingFormModal v-model="modalOpen" :posting="editing" :jobs="jobs" @saved="fetch" />
  </div>
</template>

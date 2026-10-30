<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import TrainingCertificateModal from '@/components/admin/TrainingCertificateModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { trainingRecordsService, trainingsService, type CertificateState, type PdStaffOption, type TrainingParticipant } from '@/services/professionalDevelopment'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { trainingDays } from '@/utils/professionalDevelopment'

/**
 * HRM > Professional Development > Certificates: certificates issued for
 * trainings (also listed under Teachers > Qualification), the people still
 * waiting for one, and the ones running out.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('professional-development.manage'))

const state = ref<CertificateState>('issued')
const staffId = ref('')
const actionError = ref<string | null>(null)

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<TrainingParticipant>((query) =>
  trainingRecordsService.certificates({ ...query, state: state.value, staff_id: staffId.value ? Number(staffId.value) : null }),
)

const stateOptions = computed(() => [
  { value: 'issued' as const, label: t('proDev.certificates.states.issued') },
  { value: 'pending' as const, label: t('proDev.certificates.states.pending') },
  { value: 'expiring' as const, label: t('proDev.certificates.states.expiring') },
])
function setState(value: CertificateState) {
  state.value = value
  setPage(1)
}

const staff = ref<PdStaffOption[]>([])
const staffOptions = computed(() => [
  { value: '', label: t('proDev.records.allStaff') },
  ...staff.value.map((s) => ({ value: String(s.id), label: s.name, hint: s.employee_code ?? undefined })),
])

const columns = computed(() =>
  state.value === 'pending'
    ? [
        { key: 'staff', label: t('proDev.records.staff') },
        { key: 'training', label: t('proDev.records.training') },
        { key: 'when', label: t('proDev.trainings.when') },
        { key: 'actions', label: '', align: 'text-right' },
      ]
    : [
        { key: 'number', label: t('proDev.certificates.number') },
        { key: 'staff', label: t('proDev.records.staff') },
        { key: 'training', label: t('proDev.records.training') },
        { key: 'issued', label: t('proDev.certificates.issuedOn') },
        { key: 'expires', label: t('proDev.certificates.expiresOn') },
        { key: 'actions', label: '', align: 'text-right' },
      ],
)

// --- Actions ------------------------------------------------------------------
const modalOpen = ref(false)
const current = ref<TrainingParticipant | null>(null)

function openIssue(row: TrainingParticipant) {
  current.value = row
  modalOpen.value = true
}

async function download(row: TrainingParticipant) {
  actionError.value = null
  try {
    await trainingRecordsService.downloadCertificate(row)
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('proDev.loadFailed')
  }
}

async function revoke(row: TrainingParticipant) {
  if (!(await confirmDialog.confirm({ message: t('proDev.certificates.revokeConfirm', { no: row.certificate?.no ?? '', name: row.staff?.name ?? '' }), danger: true }))) return
  actionError.value = null
  try {
    await trainingRecordsService.revokeCertificate(row.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('proDev.deleteFailed')
  }
}

onMounted(async () => {
  void fetch()
  staff.value = await trainingsService.staffOptions().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="inline-flex rounded-lg border border-neutral-300 bg-white p-0.5 shadow-sm" role="group">
        <button
          v-for="o in stateOptions"
          :key="o.value"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="state === o.value ? 'bg-primary-500 text-secondary-900' : 'text-neutral-600 hover:bg-neutral-100'"
          :aria-pressed="state === o.value"
          @click="setState(o.value)"
        >
          {{ o.label }}
        </button>
      </div>
      <SearchableSelect v-model="staffId" class="w-full sm:w-64" :options="staffOptions" :placeholder="t('proDev.records.pickStaff')" @update:model-value="setPage(1)" />
      <input
        type="search"
        :placeholder="t('proDev.certificates.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <p v-if="state === 'pending'" class="mb-3 text-sm text-neutral-500">{{ t('proDev.certificates.pendingHint') }}</p>
    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t(`proDev.certificates.empty.${state}`)">
        <template #cell-number="{ row }"><span class="font-medium text-neutral-900">{{ row.certificate?.no }}</span></template>
        <template #cell-staff="{ row }">
          {{ row.staff?.name ?? '—' }}
          <span v-if="row.staff?.employee_code" class="block text-xs text-neutral-500">{{ row.staff.employee_code }}</span>
        </template>
        <template #cell-training="{ row }">
          {{ row.training?.title }}
          <span v-if="row.training?.trainer" class="block text-xs text-neutral-500">{{ row.training.trainer }}</span>
        </template>
        <template #cell-when="{ row }">{{ row.training ? trainingDays(row.training.start_date, row.training.end_date, formatDate) : '—' }}</template>
        <template #cell-issued="{ row }">{{ formatDate(row.certificate?.issued_on) }}</template>
        <template #cell-expires="{ row }">
          <template v-if="row.certificate?.expires_on">
            <span :class="row.certificate.expired ? 'text-danger-600' : 'text-neutral-800'">{{ formatDate(row.certificate.expires_on) }}</span>
            <BaseBadge v-if="row.certificate.expired" variant="danger" class="ml-1">{{ t('proDev.certificates.expired') }}</BaseBadge>
          </template>
          <span v-else class="text-neutral-400">{{ t('proDev.certificates.noExpiry') }}</span>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-3 text-sm">
            <button v-if="row.certificate?.has_file" type="button" class="font-medium text-secondary-700 hover:underline" @click="download(row)">{{ t('proDev.certificates.download') }}</button>
            <button v-if="canManage" type="button" class="font-medium text-secondary-700 hover:underline" @click="openIssue(row)">
              {{ row.certificate ? t('proDev.edit') : t('proDev.certificates.issue') }}
            </button>
            <button v-if="canManage && row.certificate" type="button" class="font-medium text-danger-600 hover:text-red-700" @click="revoke(row)">{{ t('proDev.certificates.revoke') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <div class="space-y-2 sm:hidden">
      <p v-if="loading && items.length === 0" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="items.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t(`proDev.certificates.empty.${state}`) }}</p>
      <div v-for="row in items" :key="row.id" class="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm">
        <div class="flex items-start justify-between gap-2">
          <p class="min-w-0 truncate font-medium text-neutral-900">{{ row.staff?.name }}</p>
          <BaseBadge v-if="row.certificate" :variant="row.certificate.expired ? 'danger' : 'success'">{{ row.certificate.no }}</BaseBadge>
        </div>
        <p class="mt-1 text-sm text-neutral-700">{{ row.training?.title }}</p>
        <p class="mt-0.5 text-xs text-neutral-500">
          <template v-if="row.certificate">
            {{ t('proDev.certificates.issuedOn') }} {{ formatDate(row.certificate.issued_on) }}
            <template v-if="row.certificate.expires_on"> · {{ t('proDev.certificates.expiresOn') }} {{ formatDate(row.certificate.expires_on) }}</template>
          </template>
          <template v-else-if="row.training">{{ trainingDays(row.training.start_date, row.training.end_date, formatDate) }}</template>
        </p>
        <div class="mt-2 flex flex-wrap gap-3 text-sm">
          <button v-if="row.certificate?.has_file" type="button" class="font-medium text-secondary-700 hover:underline" @click="download(row)">{{ t('proDev.certificates.download') }}</button>
          <button v-if="canManage" type="button" class="font-medium text-secondary-700 hover:underline" @click="openIssue(row)">
            {{ row.certificate ? t('proDev.edit') : t('proDev.certificates.issue') }}
          </button>
          <button v-if="canManage && row.certificate" type="button" class="font-medium text-danger-600" @click="revoke(row)">{{ t('proDev.certificates.revoke') }}</button>
        </div>
      </div>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <TrainingCertificateModal v-model="modalOpen" :participant="current" @saved="fetch" />
  </div>
</template>

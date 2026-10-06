<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import OfferLetterFormModal from '@/components/admin/OfferLetterFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { OFFER_STATUS_VARIANT, OFFER_STATUSES, offerLettersService, type OfferLetter, type OfferStatus } from '@/services/offerLetters'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { useSiteStore } from '@/stores/site'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'
import { printOfferLetter } from '@/utils/printOfferLetter'

/**
 * HRM > Recruitment > Offer letter: every offer, its answer, and the last
 * step — Hire, which opens New staff pre-filled from the offer (see
 * StaffForm.vue's `?from_offer=`). Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const site = useSiteStore()
const confirmDialog = useConfirmDialogStore()

const canUpdate = computed(() => auth.can('recruitment.update'))
const canDelete = computed(() => auth.can('recruitment.delete'))
const canHire = computed(() => auth.can('recruitment.update') && auth.can('staff.create'))

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<OfferLetter>((query) => offerLettersService.list(query))

const statusFilter = ref('')
const statusOptions = computed(() => [
  { value: '', label: t('admin.recruitment.manpower.allStatuses') },
  ...OFFER_STATUSES.map((status) => ({ value: status, label: t(`admin.recruitment.offerStatuses.${status}`) })),
])

function onStatus(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}

const columns = computed(() => [
  { key: 'reference', label: t('admin.recruitment.manpower.reference') },
  { key: 'applicant', label: t('admin.recruitment.applicants.name') },
  { key: 'salary', label: t('admin.recruitment.offers.salary') },
  { key: 'start_date', label: t('admin.recruitment.offers.startDate') },
  { key: 'expires_on', label: t('admin.recruitment.offers.expiresOn') },
  { key: 'status', label: t('admin.recruitment.positions.status') },
  { key: 'actions', label: t('admin.recruitment.manpower.actions'), align: 'text-right' },
])

/** The next answers this offer can be given. */
function nextStatuses(offer: OfferLetter): OfferStatus[] {
  if (offer.hired_staff_id) return []
  switch (offer.status) {
    case 'draft':
      return ['sent', 'withdrawn']
    case 'sent':
      return ['accepted', 'declined', 'withdrawn']
    default:
      return []
  }
}

function mayEdit(offer: OfferLetter): boolean {
  return canUpdate.value && ['draft', 'sent'].includes(offer.status)
}

const actionError = ref<string | null>(null)

async function setStatus(offer: OfferLetter, status: OfferStatus) {
  const confirmKey = status === 'declined' ? 'admin.recruitment.offers.confirmDeclined' : status === 'withdrawn' ? 'admin.recruitment.offers.confirmWithdrawn' : null
  if (confirmKey && !(await confirmDialog.confirm({ message: t(confirmKey), danger: true }))) return

  actionError.value = null
  try {
    await offerLettersService.update(offer.id, { status })
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.offers.saveFailed')
  }
}

async function remove(offer: OfferLetter) {
  if (!(await confirmDialog.confirm({ message: t('admin.recruitment.offers.deleteConfirm', { reference: offer.reference }), danger: true }))) return
  actionError.value = null
  try {
    await offerLettersService.remove(offer.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.manpower.deleteFailed')
  }
}

function print(offer: OfferLetter) {
  printOfferLetter(offer, site.info)
}

function hire(offer: OfferLetter) {
  void router.push({ path: '/admin/staff/new', query: { from_offer: offer.id } })
}

const formOpen = ref(false)
const editing = ref<OfferLetter | null>(null)

function openEdit(offer: OfferLetter) {
  editing.value = offer
  formOpen.value = true
}

onMounted(() => {
  void fetch()
  void site.load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-xs rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-44" :model-value="statusFilter" :options="statusOptions" @update:model-value="onStatus" />
      <p class="ml-auto text-sm text-neutral-500">{{ t('admin.recruitment.offers.newHint') }}</p>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.recruitment.offers.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-800">{{ row.applicant?.name }}</p>
              <p class="truncate text-xs text-neutral-500">{{ row.reference }} · {{ row.position_title }}</p>
            </div>
            <BaseBadge :variant="OFFER_STATUS_VARIANT[row.status]" class="shrink-0">{{ t(`admin.recruitment.offerStatuses.${row.status}`) }}</BaseBadge>
          </div>
          <p class="mt-2 text-xs text-neutral-600">
            {{ formatMoney(row.salary, row.salary_currency) }} · {{ t('admin.recruitment.offers.startsOn', { date: formatDate(row.start_date) }) }}
          </p>
          <p v-if="row.hired_staff_id" class="text-xs font-medium text-success-600">{{ t('admin.recruitment.offers.hired') }}</p>
          <div class="mt-2 flex flex-wrap items-center justify-end gap-2">
            <BaseButton v-for="status in canUpdate ? nextStatuses(row) : []" :key="status" size="sm" variant="outline" @click="setStatus(row, status)">
              {{ t(`admin.recruitment.offers.mark.${status}`) }}
            </BaseButton>
            <BaseButton v-if="canHire && row.status === 'accepted' && !row.hired_staff_id" size="sm" @click="hire(row)">{{ t('admin.recruitment.offers.hire') }}</BaseButton>
            <button type="button" class="text-sm font-medium text-primary-700" @click="print(row)">{{ t('admin.recruitment.offers.print') }}</button>
            <EditIconButton v-if="mayEdit(row)" @click="openEdit(row)" />
            <button v-if="canDelete && row.status === 'draft'" type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.recruitment.manpower.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.recruitment.offers.empty')">
        <template #cell-applicant="{ row }">
          <p class="font-medium text-neutral-800">{{ row.applicant?.name }}</p>
          <p class="text-xs text-neutral-500">{{ row.position_title }}</p>
        </template>
        <template #cell-salary="{ row }">{{ formatMoney(row.salary, row.salary_currency) }}</template>
        <template #cell-start_date="{ row }">{{ formatDate(row.start_date) }}</template>
        <template #cell-expires_on="{ row }">{{ formatDate(row.expires_on) }}</template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="OFFER_STATUS_VARIANT[row.status as OfferStatus]">{{ t(`admin.recruitment.offerStatuses.${row.status}`) }}</BaseBadge>
          <p v-if="row.hired_staff_id" class="mt-1 text-xs font-medium text-success-600">{{ t('admin.recruitment.offers.hired') }}</p>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex flex-wrap items-center justify-end gap-2">
            <BaseButton v-for="status in canUpdate ? nextStatuses(row as OfferLetter) : []" :key="status" size="sm" variant="outline" @click="setStatus(row as OfferLetter, status)">
              {{ t(`admin.recruitment.offers.mark.${status}`) }}
            </BaseButton>
            <BaseButton v-if="canHire && row.status === 'accepted' && !row.hired_staff_id" size="sm" @click="hire(row as OfferLetter)">
              {{ t('admin.recruitment.offers.hire') }}
            </BaseButton>
            <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="print(row as OfferLetter)">{{ t('admin.recruitment.offers.print') }}</button>
            <EditIconButton v-if="mayEdit(row as OfferLetter)" @click="openEdit(row as OfferLetter)" />
            <button v-if="canDelete && row.status === 'draft'" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as OfferLetter)">
              {{ t('admin.recruitment.manpower.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <OfferLetterFormModal v-model="formOpen" :offer="editing" @saved="fetch" />
  </div>
</template>

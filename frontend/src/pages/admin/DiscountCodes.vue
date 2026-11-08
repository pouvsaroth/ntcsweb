<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import DiscountCodeBulkModal from '@/components/admin/DiscountCodeBulkModal.vue'
import DiscountCodeFormModal from '@/components/admin/DiscountCodeFormModal.vue'
import InvoicesTabs from '@/components/admin/InvoicesTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { discountCodesService, type DiscountCode, type DiscountEffectiveStatus } from '@/services/discountCodes'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

// Invoices > Discount codes: codes students use for money off when they enroll.
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canCreate = computed(() => auth.can('discount-codes.create'))
const canUpdate = computed(() => auth.can('discount-codes.update'))
const canDelete = computed(() => auth.can('discount-codes.delete'))
const canManage = computed(() => canUpdate.value || canDelete.value)

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<DiscountCode>((query) => discountCodesService.list(query))

const audienceFilter = ref('')
const audienceOptions = computed(() => [
  { value: '', label: t('admin.discountCodes.allAudiences') },
  { value: 'public', label: t('admin.discountCodes.audience.public') },
  { value: 'personal', label: t('admin.discountCodes.audience.personal') },
])
function changeAudience(value: string) {
  audienceFilter.value = value
  setFilter('audience', value || undefined)
}

const columns = computed(() => [
  { key: 'code', label: t('admin.discountCodes.code') },
  { key: 'discount', label: t('admin.discountCodes.discount') },
  { key: 'audience', label: t('admin.discountCodes.audienceLabel') },
  { key: 'dates', label: t('admin.discountCodes.validDates') },
  { key: 'uses', label: t('admin.discountCodes.uses'), align: 'text-right' },
  { key: 'status', label: t('admin.discountCodes.status') },
  ...(canManage.value ? [{ key: 'actions', label: '', align: 'text-right' }] : []),
])

const statusVariant: Record<DiscountEffectiveStatus, 'success' | 'neutral' | 'warning' | 'danger' | 'primary'> = {
  active: 'success',
  scheduled: 'primary',
  expired: 'danger',
  used_up: 'warning',
  inactive: 'neutral',
}

/** "10% (up to $20.00)" or "$5.00". */
function discountText(row: DiscountCode): string {
  if (row.discount_type === 'amount') return formatMoney(row.value, row.currency)
  const percent = `${Number(row.value)}%`
  return row.max_discount ? t('admin.discountCodes.percentUpTo', { percent, max: formatMoney(row.max_discount, row.currency) }) : percent
}

function usesText(row: DiscountCode): string {
  return row.max_uses ? `${row.uses_count} / ${row.max_uses}` : String(row.uses_count)
}

function audienceText(row: DiscountCode): string {
  return row.audience === 'personal'
    ? t('admin.discountCodes.personalCount', { count: row.students_count ?? 0 })
    : t('admin.discountCodes.audience.public')
}

const modalOpen = ref(false)
const bulkOpen = ref(false)
const editing = ref<DiscountCode | null>(null)
const deleteError = ref<string | null>(null)

function open(row: DiscountCode | null) {
  editing.value = row
  modalOpen.value = true
}

async function remove(row: DiscountCode) {
  if (!(await confirmDialog.confirm({ message: t('admin.discountCodes.deleteConfirm', { code: row.code }), danger: true }))) return
  deleteError.value = null
  try {
    await discountCodesService.remove(row.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.discountCodes.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <InvoicesTabs />

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
        <input
          type="search"
          :placeholder="t('common.searchPlaceholder')"
          class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          @input="setSearch(($event.target as HTMLInputElement).value)"
        />
        <BaseSelect class="sm:w-44" :model-value="audienceFilter" :options="audienceOptions" @update:model-value="changeAudience" />
      </div>
      <div v-if="canCreate" class="flex shrink-0 flex-wrap gap-2">
        <BaseButton variant="outline" @click="bulkOpen = true">{{ t('admin.discountCodes.bulk.open') }}</BaseButton>
        <BaseButton @click="open(null)">{{ t('admin.discountCodes.add') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.discountCodes.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-mono font-semibold text-neutral-900">{{ row.code }}</p>
              <p class="mt-0.5 truncate text-xs text-neutral-500">{{ row.name }}</p>
            </div>
            <BaseBadge :variant="statusVariant[row.effective_status]">{{ t(`admin.discountCodes.effective.${row.effective_status}`) }}</BaseBadge>
          </div>
          <p class="mt-2 text-sm font-medium text-neutral-800">{{ discountText(row) }}</p>
          <p class="mt-0.5 text-xs text-neutral-500">{{ formatDate(row.starts_on) }} → {{ formatDate(row.expires_on) }} · {{ audienceText(row) }}</p>
          <div class="mt-2 flex items-center justify-between gap-2">
            <p class="text-xs text-neutral-500">{{ t('admin.discountCodes.uses') }}: {{ usesText(row) }}</p>
            <div v-if="canManage" class="flex items-center gap-3">
              <EditIconButton v-if="canUpdate" @click="open(row)" />
              <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.discountCodes.delete') }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.discountCodes.empty')">
      <template #cell-code="{ row }">
        <p class="font-mono font-semibold text-neutral-900">{{ row.code }}</p>
        <p class="text-xs text-neutral-500">{{ row.name }}</p>
      </template>
      <template #cell-discount="{ row }">
        <p>{{ discountText(row) }}</p>
        <p class="text-xs text-neutral-500">
          {{ row.course_packages?.length ? row.course_packages.map((c: { name: string }) => c.name).join(', ') : t('admin.discountCodes.allCourses') }}
        </p>
      </template>
      <template #cell-audience="{ row }">{{ audienceText(row) }}</template>
      <template #cell-dates="{ row }">{{ formatDate(row.starts_on) }} → {{ formatDate(row.expires_on) }}</template>
      <template #cell-uses="{ row }">{{ usesText(row) }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.effective_status as DiscountEffectiveStatus]">{{ t(`admin.discountCodes.effective.${row.effective_status}`) }}</BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton v-if="canUpdate" @click="open(row)" />
          <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.discountCodes.delete') }}</button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <DiscountCodeFormModal v-model="modalOpen" :discount-code="editing" @saved="fetch()" />
    <DiscountCodeBulkModal v-if="canCreate" v-model="bulkOpen" @saved="fetch()" />
  </div>
</template>

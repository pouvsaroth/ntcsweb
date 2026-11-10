<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import LoanCustomerFormModal from '@/components/admin/LoanCustomerFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { loanCustomersService, type LoanCustomer } from '@/services/loanCustomers'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

// Loan > Customers: the people who keep savings with the owner or borrow from them.
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canCreate = computed(() => auth.can('loan-customers.create'))
const canUpdate = computed(() => auth.can('loan-customers.update'))
const canDelete = computed(() => auth.can('loan-customers.delete'))
const canManage = computed(() => canUpdate.value || canDelete.value)

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<LoanCustomer>((query) =>
  loanCustomersService.list(query),
)

const statusFilter = ref('true')
const statusOptions = computed(() => [
  { value: 'true', label: t('admin.loanCustomers.active') },
  { value: 'false', label: t('admin.loanCustomers.inactive') },
  { value: '', label: t('admin.loanCustomers.filterAll') },
])
function onStatus(value: string) {
  statusFilter.value = value
  setFilter('is_active', value || undefined)
}

const columns = computed(() => [
  { key: 'code', label: t('admin.loanCustomers.code') },
  { key: 'name', label: t('admin.loanCustomers.name') },
  { key: 'gender', label: t('admin.loanCustomers.gender') },
  { key: 'phone', label: t('admin.loanCustomers.phone') },
  { key: 'id_card_no', label: t('admin.loanCustomers.idCard') },
  { key: 'is_active', label: t('admin.loanCustomers.status') },
  ...(canManage.value ? [{ key: 'actions', label: '', align: 'text-right' }] : []),
])

function genderLabel(gender: LoanCustomer['gender']): string {
  return gender ? t(`admin.loanCustomers.${gender}`) : '—'
}

const modalOpen = ref(false)
const editing = ref<LoanCustomer | null>(null)
const deleteError = ref<string | null>(null)

function open(customer: LoanCustomer | null) {
  editing.value = customer
  modalOpen.value = true
}

async function remove(customer: LoanCustomer) {
  if (!(await confirmDialog.confirm({ message: t('admin.loanCustomers.deleteConfirm'), danger: true }))) return
  deleteError.value = null
  try {
    await loanCustomersService.remove(customer.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.loanCustomers.deleteFailed')
  }
}

onMounted(() => {
  setFilter('is_active', 'true')
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.loanCustomers.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.loanCustomers.subtitle') }}</p>
    </div>

    <div class="mb-6 flex flex-wrap items-center gap-3">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-36" :model-value="statusFilter" :options="statusOptions" @update:model-value="onStatus" />
      <BaseButton v-if="canCreate" class="ml-auto shrink-0" @click="open(null)">{{ t('admin.loanCustomers.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.loanCustomers.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.name }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">
                {{ row.code }}<template v-if="row.gender"> · {{ genderLabel(row.gender) }}</template><template v-if="row.phone"> · {{ row.phone }}</template>
              </p>
            </div>
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
              {{ row.is_active ? t('admin.loanCustomers.active') : t('admin.loanCustomers.inactive') }}
            </BaseBadge>
          </div>
          <div v-if="canManage" class="mt-2 flex items-center justify-end gap-3">
            <EditIconButton v-if="canUpdate" @click="open(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
              {{ t('admin.loanCustomers.delete') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.loanCustomers.empty')">
      <template #cell-code="{ row }"><span class="tabular-nums text-neutral-600">{{ row.code }}</span></template>
      <template #cell-name="{ row }">
        <p class="font-medium text-neutral-900">{{ row.name }}</p>
        <p v-if="row.address" class="max-w-xs truncate text-xs text-neutral-500">{{ row.address }}</p>
      </template>
      <template #cell-gender="{ row }">{{ genderLabel(row.gender) }}</template>
      <template #cell-phone="{ row }">{{ row.phone ?? '—' }}</template>
      <template #cell-id_card_no="{ row }">{{ row.id_card_no ?? '—' }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.loanCustomers.active') : t('admin.loanCustomers.inactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton v-if="canUpdate" @click="open(row)" />
          <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.loanCustomers.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <LoanCustomerFormModal v-model="modalOpen" :customer="editing" @saved="fetch()" />
  </div>
</template>

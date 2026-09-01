<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AccountFormModal from '@/components/admin/AccountFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { accountsService, accountTypes, type Account, type AccountType } from '@/services/accounting'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSort, sort, setSearch, setFilter, fetch } = usePaginatedResource<Account>(
  (query) => accountsService.list(query),
)

const selectedType = ref('')

function typeKey(type: AccountType): string {
  return type.charAt(0) + type.slice(1).toLowerCase()
}

const typeFilterOptions = computed(() => accountTypes.map((type) => ({ value: type, label: t(`admin.accounts.type${typeKey(type)}`) })))

function onTypeFilterChange(value: string) {
  selectedType.value = value
  setFilter('type', value || undefined)
}

const columns = [
  { key: 'code', label: t('admin.accounts.columnCode'), sortable: true },
  { key: 'name', label: t('admin.accounts.columnName'), sortable: true },
  { key: 'type', label: t('admin.accounts.columnType') },
  { key: 'is_bank_or_cash', label: t('admin.accounts.columnBankOrCash') },
  { key: 'is_active', label: t('admin.accounts.columnStatus') },
  { key: 'actions', label: t('admin.accounts.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingAccount = ref<Account | null>(null)

function openCreate() {
  editingAccount.value = null
  modalOpen.value = true
}

function openEdit(account: Account) {
  editingAccount.value = account
  modalOpen.value = true
}

async function toggleActive(account: Account) {
  const confirmMessage = account.is_active ? t('admin.accounts.deactivateConfirm') : t('admin.accounts.reactivateConfirm')
  if (!window.confirm(confirmMessage)) return

  if (account.is_active) {
    await accountsService.deactivate(account.id)
  } else {
    await accountsService.reactivate(account.id)
  }
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.accounts.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.accounts.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.accounts.addAccount') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :placeholder="t('admin.accounts.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect
        :model-value="selectedType"
        :options="typeFilterOptions"
        :placeholder="t('admin.accounts.filterAllTypes')"
        @update:model-value="onTypeFilterChange"
      />
    </div>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.accounts.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-type="{ row }">{{ t(`admin.accounts.type${typeKey(row.type as AccountType)}`) }}</template>
      <template #cell-is_bank_or_cash="{ row }">
        <BaseBadge v-if="row.is_bank_or_cash" variant="primary">{{ t('admin.accounts.bankOrCashYes') }}</BaseBadge>
        <span v-else class="text-neutral-400">—</span>
      </template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.accounts.statusActive') : t('admin.accounts.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="toggleActive(row)">
            {{ row.is_active ? t('admin.accounts.deactivate') : t('admin.accounts.reactivate') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <AccountFormModal v-model="modalOpen" :account="editingAccount" @saved="fetch" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import LenderFormModal from '@/components/admin/LenderFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { lendersService, type Lender } from '@/services/borrowings'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

// Accounting > Borrowings > Lenders: the companies and people money is borrowed from.
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('borrowings.manage'))

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Lender>((query) => lendersService.list(query))

const columns = computed(() => [
  { key: 'name', label: t('admin.borrowings.lenderName') },
  { key: 'type', label: t('admin.borrowings.lenderTypeLabel') },
  { key: 'phone', label: t('admin.borrowings.phone') },
  { key: 'borrowings_count', label: t('admin.borrowings.loansCount'), align: 'text-right' },
  { key: 'is_active', label: t('admin.borrowings.status') },
  ...(canManage.value ? [{ key: 'actions', label: '', align: 'text-right' }] : []),
])

const modalOpen = ref(false)
const editing = ref<Lender | null>(null)
const deleteError = ref<string | null>(null)

function open(lender: Lender | null) {
  editing.value = lender
  modalOpen.value = true
}

async function remove(lender: Lender) {
  if (!(await confirmDialog.confirm({ message: t('admin.borrowings.deleteLenderConfirm'), danger: true }))) return
  deleteError.value = null
  try {
    await lendersService.remove(lender.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between gap-3">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="canManage" class="shrink-0" @click="open(null)">{{ t('admin.borrowings.addLender') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.borrowings.noLenders') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.name }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">{{ t(`admin.borrowings.lenderType.${row.type}`) }}<template v-if="row.phone"> · {{ row.phone }}</template></p>
            </div>
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.borrowings.active') : t('admin.borrowings.inactive') }}</BaseBadge>
          </div>
          <div class="mt-2 flex items-center justify-between gap-2">
            <p class="text-xs text-neutral-500">{{ t('admin.borrowings.loansCount') }}: {{ row.borrowings_count ?? 0 }}</p>
            <div v-if="canManage" class="flex items-center gap-3">
              <EditIconButton @click="open(row)" />
              <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.borrowings.delete') }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.borrowings.noLenders')">
      <template #cell-name="{ row }">
        <p class="font-medium text-neutral-900">{{ row.name }}</p>
        <p v-if="row.contact_person" class="text-xs text-neutral-500">{{ row.contact_person }}</p>
      </template>
      <template #cell-type="{ row }">{{ t(`admin.borrowings.lenderType.${row.type}`) }}</template>
      <template #cell-phone="{ row }">{{ row.phone ?? '—' }}</template>
      <template #cell-borrowings_count="{ row }">{{ row.borrowings_count ?? 0 }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.borrowings.active') : t('admin.borrowings.inactive') }}</BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="open(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.borrowings.delete') }}</button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <LenderFormModal v-model="modalOpen" :lender="editing" @saved="fetch()" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CompanyFormModal from '@/components/admin/CompanyFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { companiesService, type Company } from '@/services/companies'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canCreate = computed(() => auth.can('companies.create'))
const canUpdate = computed(() => auth.can('companies.update'))
const canDelete = computed(() => auth.can('companies.delete'))

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Company>((query) => companiesService.list(query))

const columns = computed(() => [
  { key: 'name', label: t('admin.companies.columnName') },
  { key: 'contact_person', label: t('admin.companies.columnContact') },
  { key: 'phone', label: t('admin.companies.columnPhone') },
  { key: 'classes_count', label: t('admin.companies.columnClasses') },
  { key: 'is_active', label: t('admin.companies.columnStatus') },
  { key: 'actions', label: t('admin.companies.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingCompany = ref<Company | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingCompany.value = null
  modalOpen.value = true
}

function openEdit(company: Company) {
  editingCompany.value = company
  modalOpen.value = true
}

async function remove(company: Company) {
  if (!(await confirmDialog.confirm({ message: t('admin.companies.deleteConfirm'), danger: true }))) return
  deleteError.value = null

  try {
    await companiesService.remove(company.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.companies.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="canCreate" @click="openCreate">{{ t('admin.companies.addCompany') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <!-- Phone: one card per company. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.companies.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ row.name }}</p>
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
              {{ row.is_active ? t('admin.companies.statusActive') : t('admin.companies.statusInactive') }}
            </BaseBadge>
          </div>
          <p class="text-xs text-neutral-500">{{ [row.contact_person, row.phone].filter(Boolean).join(' · ') || '—' }}</p>
          <p class="text-xs text-neutral-500">{{ t('admin.companies.columnClasses') }}: {{ row.classes_count ?? 0 }}</p>
          <div v-if="canUpdate || canDelete" class="mt-2 flex justify-end gap-3">
            <EditIconButton v-if="canUpdate" @click="openEdit(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.companies.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.companies.emptyMessage')">
      <template #cell-contact_person="{ row }">{{ row.contact_person ?? '—' }}</template>
      <template #cell-phone="{ row }">{{ row.phone ?? '—' }}</template>
      <template #cell-classes_count="{ row }">{{ row.classes_count ?? 0 }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.companies.statusActive') : t('admin.companies.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton v-if="canUpdate" @click="openEdit(row)" />
          <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.companies.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CompanyFormModal v-model="modalOpen" :company="editingCompany" @saved="fetch" />
  </div>
</template>

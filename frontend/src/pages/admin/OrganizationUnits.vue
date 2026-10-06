<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import OrganizationUnitFormModal from '@/components/admin/OrganizationUnitFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { organizationUnitsService, type OrganizationUnit, type OrganizationUnitKind } from '@/services/organizationUnits'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * One page for each of HRM > Organization Management's simple lists
 * (Branch, Team, Job grade, Job level) — the route passes which one as
 * `kind` (see router/admin.ts). Same layout as Departments.vue.
 */
const props = defineProps<{ kind: OrganizationUnitKind }>()

const { t } = useI18n()
const auth = useAuthStore()

const service = organizationUnitsService(props.kind)
const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<OrganizationUnit>((query) => service.list(query))

const canCreate = computed(() => auth.can('organization.create'))
const canUpdate = computed(() => auth.can('organization.update'))
const canDelete = computed(() => auth.can('organization.delete'))

const columns = computed(() => [
  { key: 'code', label: t('admin.organization.code') },
  { key: 'name', label: t('admin.organization.name') },
  ...(props.kind === 'branches' ? [{ key: 'phone', label: t('admin.organization.phone') }] : []),
  ...(props.kind === 'teams' ? [{ key: 'department', label: t('admin.organization.tabs.department') }] : []),
  { key: 'is_active', label: t('admin.organization.status') },
  ...(canUpdate.value || canDelete.value ? [{ key: 'actions', label: t('admin.organization.actions'), align: 'text-right' }] : []),
])

const modalOpen = ref(false)
const editing = ref<OrganizationUnit | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editing.value = null
  modalOpen.value = true
}

function openEdit(unit: OrganizationUnit) {
  editing.value = unit
  modalOpen.value = true
}

async function remove(unit: OrganizationUnit) {
  if (!window.confirm(t('admin.organization.deleteConfirm', { name: unit.name }))) return
  deleteError.value = null

  try {
    await service.remove(unit.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
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
      <BaseButton v-if="canCreate" @click="openCreate">{{ t(`admin.organization.kinds.${kind}.add`) }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t(`admin.organization.kinds.${kind}.empty`)">
      <template #cell-phone="{ row }">{{ row.phone || '—' }}</template>
      <template #cell-department="{ row }">{{ row.department?.name ?? '—' }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton v-if="canUpdate" @click="openEdit(row)" />
          <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.organization.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <OrganizationUnitFormModal v-model="modalOpen" :kind="kind" :unit="editing" @saved="fetch" />
  </div>
</template>

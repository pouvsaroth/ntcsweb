<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ApprovalGroupFormModal from '@/components/admin/ApprovalGroupFormModal.vue'
import ApprovalFlowTabs from '@/components/admin/ApprovalFlowTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { approvalGroupsService, type ApprovalGroup } from '@/services/approvalGroups'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<ApprovalGroup>(
  (query) => approvalGroupsService.list(query),
  { perPage: 25 },
)

const columns = computed(() => [
  { key: 'name', label: t('admin.approvalGroups.columnName') },
  { key: 'members', label: t('admin.approvalGroups.columnMembers') },
  { key: 'actions', label: t('admin.approvalGroups.columnActions'), align: 'text-right' },
])

/** Past this many, the Members cell shows "+N more" instead of every name. */
const MEMBERS_SHOWN = 4

const modalOpen = ref(false)
const editingGroup = ref<ApprovalGroup | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingGroup.value = null
  modalOpen.value = true
}

function openEdit(group: ApprovalGroup) {
  editingGroup.value = group
  modalOpen.value = true
}

async function remove(group: ApprovalGroup) {
  if (!(await confirmDialog.confirm({ message: t('admin.approvalGroups.deleteConfirm', { name: group.name }), danger: true }))) return
  deleteError.value = null
  try {
    await approvalGroupsService.remove(group.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.approvalGroups.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <ApprovalFlowTabs />

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.approvalGroups.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.approvalGroups.subtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.approvalGroups.addGroup') }}</BaseButton>
    </div>

    <input
      type="search"
      :placeholder="t('common.searchPlaceholder')"
      class="mb-4 block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
      @input="setSearch(($event.target as HTMLInputElement).value)"
    />

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.approvalGroups.emptyMessage')">
      <template #cell-name="{ row }">
        <p class="font-medium text-neutral-900">{{ row.name }}</p>
        <p v-if="row.description" class="text-xs text-neutral-500">{{ row.description }}</p>
      </template>
      <template #cell-members="{ row }">
        <div v-if="row.members.length" class="flex flex-wrap gap-1">
          <BaseBadge v-for="member in row.members.slice(0, MEMBERS_SHOWN)" :key="member.id" variant="neutral">{{ member.name }}</BaseBadge>
          <span v-if="row.members.length > MEMBERS_SHOWN" class="self-center text-xs text-neutral-500">
            {{ t('admin.approvalGroups.moreMembers', { count: row.members.length - MEMBERS_SHOWN }) }}
          </span>
        </div>
        <span v-else class="text-sm text-neutral-400">{{ t('admin.approvalGroups.noMembers') }}</span>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.approvalGroups.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ApprovalGroupFormModal v-model="modalOpen" :group="editingGroup" @saved="fetch" />
  </div>
</template>

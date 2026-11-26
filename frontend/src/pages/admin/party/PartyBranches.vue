<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import PartyBranchFormModal from '@/components/admin/PartyBranchFormModal.vue'
import PlaceSelects from '@/components/admin/PlaceSelects.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { partyBranchesService, type PartyBranch, type PlacePick } from '@/services/partyBranches'
import type { PlaceBrief } from '@/services/partyLocations'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Political Party > Branches: the party's branches / offices, each at a
 * province, district, commune or village. Narrow the list by place (the
 * same four lists as Locations), level, status or a search; Excel exports
 * what the list keeps.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canCreate = computed(() => auth.can('party-branches.create'))
const canUpdate = computed(() => auth.can('party-branches.update'))
const canDelete = computed(() => auth.can('party-branches.delete'))
const canManage = computed(() => canUpdate.value || canDelete.value)

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<PartyBranch>((query) => partyBranchesService.list(query))

const search = ref('')
const filters = ref<Record<string, string>>({ is_active: 'true' })
function filter(key: string, value: string) {
  filters.value = { ...filters.value, [key]: value }
  setFilter(key, value || undefined)
}
function onSearch(value: string) {
  search.value = value
  setSearch(value)
}

const place = ref<PlacePick>({ province_id: '', district_id: '', commune_id: '', village_id: '' })
function onPlace(next: PlacePick) {
  place.value = next
  for (const key of ['province_id', 'district_id', 'commune_id', 'village_id'] as const) {
    if ((filters.value[key] ?? '') !== next[key]) filter(key, next[key])
  }
}

const levelOptions = computed(() => [
  { value: '', label: t('admin.partyLocations.allLevels') },
  ...(['province', 'district', 'commune', 'village'] as const).map((value) => ({ value, label: t(`admin.partyBranches.levelBranch.${value}`) })),
])
const statusOptions = computed(() => [
  { value: 'true', label: t('admin.partyBranches.active') },
  { value: 'false', label: t('admin.partyBranches.closed') },
  { value: '', label: t('admin.partyLocations.all') },
])

const columns = computed(() => [
  { key: 'code', label: t('admin.partyBranches.code') },
  { key: 'name', label: t('admin.partyBranches.name') },
  { key: 'place', label: t('admin.partyBranches.place') },
  { key: 'leader', label: t('admin.partyBranches.leader') },
  { key: 'opened_on', label: t('admin.partyBranches.openedOn') },
  { key: 'is_active', label: t('admin.partyBranches.status') },
  ...(canManage.value ? [{ key: 'actions', label: '', align: 'text-right' }] : []),
])

/** "Ou Ta Ki · Anlong Vil · Sangkae · Battambang" in Khmer (Latin). */
function placeText(row: PartyBranch): string {
  return [row.village, row.commune, row.district, row.province]
    .filter((p): p is PlaceBrief => p !== null)
    .map((p) => `${p.name_km} (${p.name_latin})`)
    .join(' · ')
}

const modalOpen = ref(false)
const editing = ref<PartyBranch | null>(null)
const actionError = ref<string | null>(null)
function open(branch: PartyBranch | null) {
  editing.value = branch
  modalOpen.value = true
}

async function remove(branch: PartyBranch) {
  if (!(await confirmDialog.confirm({ message: t('admin.partyBranches.deleteConfirm', { name: branch.name }), danger: true }))) return
  actionError.value = null
  try {
    await partyBranchesService.remove(branch.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.partyBranches.deleteFailed')
  }
}

const exporting = ref(false)
async function exportExcel() {
  exporting.value = true
  actionError.value = null
  try {
    await partyBranchesService.excel(search.value.trim(), filters.value)
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.partyBranches.exportFailed')
  } finally {
    exporting.value = false
  }
}

onMounted(() => setFilter('is_active', 'true'))
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('adminNav.items.partyBranches') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.partyBranches.subtitle') }}</p>
      </div>
      <div class="flex gap-2">
        <BaseButton variant="outline" :loading="exporting" @click="exportExcel">{{ t('admin.partyBranches.excel') }}</BaseButton>
        <BaseButton v-if="canCreate" @click="open(null)">{{ t('admin.partyBranches.add') }}</BaseButton>
      </div>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <PlaceSelects :model-value="place" :all-label="t('admin.partyLocations.all')" @update:model-value="onPlace" />
      <input
        type="search"
        :value="search"
        :placeholder="t('admin.partyBranches.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:col-span-2"
        @input="onSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect :model-value="filters.level ?? ''" :options="levelOptions" @update:model-value="filter('level', $event)" />
      <BaseSelect :model-value="filters.is_active ?? ''" :options="statusOptions" @update:model-value="filter('is_active', $event)" />
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>
    <p v-if="meta" class="mb-2 text-sm text-neutral-500">{{ t('admin.partyLocations.found', { n: meta.total }) }}</p>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.partyBranches.empty') }}</p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-medium text-neutral-900">{{ row.name }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">{{ row.code }} · {{ t(`admin.partyBranches.levelBranch.${row.level}`) }}</p>
            </div>
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.partyBranches.active') : t('admin.partyBranches.closed') }}</BaseBadge>
          </div>
          <p class="mt-1 text-xs text-neutral-600">{{ placeText(row) }}</p>
          <p v-if="row.leader_name || row.leader_phone" class="mt-1 text-sm text-neutral-700">{{ [row.leader_name, row.leader_phone].filter(Boolean).join(' · ') }}</p>
          <p v-if="row.opened_on" class="mt-0.5 text-xs text-neutral-500">{{ t('admin.partyBranches.openedOn') }}: {{ formatDate(row.opened_on) }}</p>
          <div v-if="canManage" class="mt-2 flex items-center justify-end gap-3">
            <EditIconButton v-if="canUpdate" @click="open(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.partyBranches.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.partyBranches.empty')">
      <template #cell-code="{ row }"><span class="tabular-nums text-neutral-600">{{ row.code }}</span></template>
      <template #cell-name="{ row }">
        <p class="font-medium text-neutral-900">{{ row.name }}</p>
        <p class="text-xs text-neutral-500">{{ t(`admin.partyBranches.levelBranch.${row.level}`) }}</p>
      </template>
      <template #cell-place="{ row }"><span class="block min-w-48 max-w-xs whitespace-normal text-sm text-neutral-700">{{ placeText(row) }}</span></template>
      <template #cell-leader="{ row }">
        <p>{{ row.leader_name ?? '—' }}</p>
        <p v-if="row.leader_phone" class="text-xs text-neutral-500">{{ row.leader_phone }}</p>
      </template>
      <template #cell-opened_on="{ row }"><span class="whitespace-nowrap tabular-nums">{{ row.opened_on ? formatDate(row.opened_on) : '—' }}</span></template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.partyBranches.active') : t('admin.partyBranches.closed') }}</BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex items-center justify-end gap-3">
          <EditIconButton v-if="canUpdate" @click="open(row)" />
          <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.partyBranches.delete') }}</button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <PartyBranchFormModal v-model="modalOpen" :branch="editing" @saved="fetch" />
  </div>
</template>

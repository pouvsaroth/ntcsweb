<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CommuneSeatsModal from '@/components/admin/CommuneSeatsModal.vue'
import ElectionImportModal from '@/components/admin/ElectionImportModal.vue'
import ElectionOfficeFormModal from '@/components/admin/ElectionOfficeFormModal.vue'
import PlaceSelects from '@/components/admin/PlaceSelects.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { electionOfficesService, type CommuneSeats, type ElectionOffice, type ElectionSummary } from '@/services/electionOffices'
import type { PlacePick } from '@/services/partyBranches'
import type { PlaceBrief } from '@/services/partyLocations'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * Political Party > Election offices: pick an election year and a
 * province → district → commune to see how many council seats the commune
 * has, its election offices and their voters. A wider pick adds up every
 * commune in it. Offices and seats come from an Excel import (or are typed).
 */
const { t, locale } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canCreate = computed(() => auth.can('election-offices.create'))
const canUpdate = computed(() => auth.can('election-offices.update'))
const canDelete = computed(() => auth.can('election-offices.delete'))
const canImport = computed(() => canCreate.value && canUpdate.value)
const canSetSeats = computed(() => canCreate.value || canUpdate.value)

const summary = ref<ElectionSummary | null>(null)
const offices = usePaginatedResource<ElectionOffice>(async (query) => {
  const result = await electionOfficesService.list(query)
  summary.value = result.summary
  return result
})
const seats = usePaginatedResource<CommuneSeats>((query) => electionOfficesService.seats(query))

const tab = ref<'offices' | 'seats'>('offices')
const year = ref<number>(new Date().getFullYear())
const years = ref<number[]>([])
const yearOptions = computed(() => [...new Set([...years.value, year.value])].sort((a, b) => b - a).map((y) => ({ value: String(y), label: String(y) })))

const place = ref<PlacePick>({ province_id: '', district_id: '', commune_id: '', village_id: '' })
const search = ref('')

function setBoth(key: string, value: string) {
  offices.setFilter(key, value || undefined)
  seats.setFilter(key, value || undefined)
}
function onPlace(next: PlacePick) {
  for (const key of ['province_id', 'district_id', 'commune_id'] as const) {
    if (place.value[key] !== next[key]) setBoth(key, next[key])
  }
  place.value = next
}
function onYear(value: string) {
  year.value = Number(value)
  setBoth('election_year', value)
}
function onSearch(value: string) {
  search.value = value
  offices.setSearch(value)
}
function reload() {
  void offices.fetch()
  void seats.fetch()
  void electionOfficesService.years().then((list) => (years.value = list)).catch(() => undefined)
}

/** The deepest pick in Khmer (Latin), or "All of Cambodia". */
const pickedName = computed(() => {
  const brief = summary.value?.place
  return brief ? `${brief.name_km} (${brief.name_latin})` : t('admin.electionOffices.everywhere')
})
const number = (n: number | null | undefined) => (n == null ? '—' : n.toLocaleString(locale.value === 'km' ? 'km-KH' : undefined))

function placeText(row: { village?: PlaceBrief | null; commune: PlaceBrief | null; district: PlaceBrief | null; province: PlaceBrief | null }): string {
  return [row.village ?? null, row.commune, row.district, row.province]
    .filter((p): p is PlaceBrief => p !== null)
    .map((p) => `${p.name_km} (${p.name_latin})`)
    .join(' · ')
}

const officeColumns = computed(() => [
  { key: 'code', label: t('admin.electionOffices.code') },
  { key: 'name', label: t('admin.electionOffices.name') },
  { key: 'place', label: t('admin.electionOffices.place') },
  { key: 'voters', label: t('admin.electionOffices.voters'), align: 'text-right' },
  ...(canUpdate.value || canDelete.value ? [{ key: 'actions', label: '', align: 'text-right' }] : []),
])
const seatColumns = computed(() => [
  { key: 'commune', label: t('admin.partyLocations.levels.commune') },
  { key: 'seats', label: t('admin.electionOffices.seats'), align: 'text-right' },
  { key: 'offices', label: t('admin.electionOffices.offices'), align: 'text-right' },
  { key: 'voters', label: t('admin.electionOffices.voters'), align: 'text-right' },
  ...(canSetSeats.value || canDelete.value ? [{ key: 'actions', label: '', align: 'text-right' }] : []),
])

const officeModal = ref(false)
const editing = ref<ElectionOffice | null>(null)
function openOffice(office: ElectionOffice | null) {
  editing.value = office
  officeModal.value = true
}
const seatsModal = ref(false)
const seatsPlace = ref<PlacePick>({ province_id: '', district_id: '', commune_id: '', village_id: '' })
const seatsCount = ref<number | null>(null)
function openSeats(row: CommuneSeats | null) {
  seatsPlace.value = row
    ? { province_id: String(row.province?.id ?? ''), district_id: String(row.district?.id ?? ''), commune_id: String(row.commune?.id ?? ''), village_id: '' }
    : { ...place.value, village_id: '' }
  seatsCount.value = row ? row.seats : place.value.commune_id ? summary.value?.seats || null : null
  seatsModal.value = true
}
const importModal = ref(false)
function onImported(importedYear: number) {
  if (importedYear !== year.value) onYear(String(importedYear))
  reload()
}

const actionError = ref<string | null>(null)
async function removeOffice(office: ElectionOffice) {
  if (!(await confirmDialog.confirm({ message: t('admin.electionOffices.deleteOfficeConfirm', { name: office.name }), danger: true }))) return
  actionError.value = null
  try {
    await electionOfficesService.remove(office.id)
    reload()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.electionOffices.deleteFailed')
  }
}
async function removeSeats(row: CommuneSeats) {
  if (!(await confirmDialog.confirm({ message: t('admin.electionOffices.deleteSeatsConfirm', { name: row.commune?.name_latin ?? '' }), danger: true }))) return
  actionError.value = null
  try {
    await electionOfficesService.removeSeats(row.id)
    reload()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.electionOffices.deleteFailed')
  }
}

onMounted(async () => {
  years.value = await electionOfficesService.years().catch(() => [])
  if (years.value.length) year.value = years.value[0]
  setBoth('election_year', String(year.value))
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('adminNav.items.electionOffices') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.electionOffices.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <BaseButton v-if="canImport" variant="outline" @click="importModal = true">{{ t('admin.electionOffices.import') }}</BaseButton>
        <BaseButton v-if="canSetSeats" variant="outline" @click="openSeats(null)">{{ t('admin.electionOffices.setSeats') }}</BaseButton>
        <BaseButton v-if="canCreate" @click="openOffice(null)">{{ t('admin.electionOffices.addOffice') }}</BaseButton>
      </div>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <BaseSelect :model-value="String(year)" :options="yearOptions" :label="t('admin.electionOffices.year')" @update:model-value="onYear" />
      <PlaceSelects :model-value="place" :depth="3" :all-label="t('admin.partyLocations.all')" @update:model-value="onPlace" />
    </div>

    <!-- The picked place's totals: council seats, offices, voters. -->
    <div class="mb-4 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
      <p class="text-sm text-neutral-500">{{ pickedName }} · {{ year }}</p>
      <div class="mt-3 grid grid-cols-3 gap-3">
        <div>
          <p class="text-xs text-neutral-500">{{ place.commune_id ? t('admin.electionOffices.councilSeats') : t('admin.electionOffices.totalSeats') }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-primary-700">{{ summary?.seats ? number(summary.seats) : '—' }}</p>
          <p v-if="!place.commune_id && summary?.communes_with_seats" class="text-xs text-neutral-500">{{ t('admin.electionOffices.inCommunes', { n: summary.communes_with_seats }) }}</p>
        </div>
        <div>
          <p class="text-xs text-neutral-500">{{ t('admin.electionOffices.offices') }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ number(summary?.offices) }}</p>
        </div>
        <div>
          <p class="text-xs text-neutral-500">{{ t('admin.electionOffices.voters') }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ number(summary?.voters) }}</p>
        </div>
      </div>
    </div>

    <BaseAlert v-if="offices.error.value || seats.error.value || actionError" variant="danger" class="mb-4">{{ offices.error.value || seats.error.value || actionError }}</BaseAlert>

    <div class="mb-3 flex flex-wrap items-center gap-2">
      <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-0.5">
        <button
          v-for="name in ['offices', 'seats'] as const"
          :key="name"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium"
          :class="tab === name ? 'bg-primary-50 text-primary-700' : 'text-neutral-600 hover:text-neutral-900'"
          @click="tab = name"
        >
          {{ name === 'offices' ? t('admin.electionOffices.tabOffices') : t('admin.electionOffices.tabSeats') }}
        </button>
      </div>
      <input
        v-if="tab === 'offices'"
        type="search"
        :value="search"
        :placeholder="t('admin.electionOffices.searchPlaceholder')"
        class="block min-w-0 basis-full rounded-lg border border-neutral-300 px-3 py-2 text-sm sm:basis-0 sm:flex-1 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="onSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <template v-if="tab === 'offices'">
      <!-- Cards on small screens (below sm) instead of the table. -->
      <div class="sm:hidden">
        <div v-if="offices.loading.value" class="flex justify-center py-10"><BaseSpinner /></div>
        <p v-else-if="offices.items.value.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.electionOffices.noOffices') }}</p>
        <div v-else class="space-y-2">
          <div v-for="row in offices.items.value" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-medium text-neutral-900">{{ row.name }}</p>
                <p class="mt-0.5 text-xs text-neutral-500">{{ row.code }}<template v-if="row.location"> · {{ row.location }}</template></p>
              </div>
              <p class="text-right text-sm tabular-nums text-neutral-900">{{ number(row.voters) }}<span class="block text-xs text-neutral-500">{{ t('admin.electionOffices.voters') }}</span></p>
            </div>
            <p class="mt-1 text-xs text-neutral-600">{{ placeText(row) }}</p>
            <div v-if="canUpdate || canDelete" class="mt-2 flex items-center justify-end gap-3">
              <EditIconButton v-if="canUpdate" @click="openOffice(row)" />
              <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeOffice(row)">{{ t('admin.electionOffices.delete') }}</button>
            </div>
          </div>
        </div>
      </div>

      <DataTable class="hidden sm:block" :columns="officeColumns" :rows="offices.items.value" row-key="id" :loading="offices.loading.value" :empty-message="t('admin.electionOffices.noOffices')">
        <template #cell-code="{ row }"><span class="tabular-nums text-neutral-600">{{ row.code }}</span></template>
        <template #cell-name="{ row }">
          <p class="font-medium text-neutral-900">{{ row.name }}</p>
          <p v-if="row.location" class="text-xs text-neutral-500">{{ row.location }}</p>
        </template>
        <template #cell-place="{ row }"><span class="block min-w-48 max-w-xs whitespace-normal text-sm text-neutral-700">{{ placeText(row) }}</span></template>
        <template #cell-voters="{ row }"><span class="tabular-nums">{{ number(row.voters) }}</span></template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-3">
            <EditIconButton v-if="canUpdate" @click="openOffice(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeOffice(row)">{{ t('admin.electionOffices.delete') }}</button>
          </div>
        </template>
      </DataTable>

      <BasePagination v-if="offices.meta.value" :meta="offices.meta.value" sticky class="mt-4" @update:page="offices.setPage" />
    </template>

    <template v-else>
      <div class="sm:hidden">
        <div v-if="seats.loading.value" class="flex justify-center py-10"><BaseSpinner /></div>
        <p v-else-if="seats.items.value.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.electionOffices.noSeats') }}</p>
        <div v-else class="space-y-2">
          <div v-for="row in seats.items.value" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <p class="min-w-0 text-sm text-neutral-800">{{ placeText(row) }}</p>
              <p class="text-right text-lg font-semibold tabular-nums text-primary-700">{{ row.seats }}<span class="block text-xs font-normal text-neutral-500">{{ t('admin.electionOffices.seats') }}</span></p>
            </div>
            <p class="mt-1 text-xs text-neutral-500">{{ t('admin.electionOffices.offices') }}: {{ number(row.offices) }} · {{ t('admin.electionOffices.voters') }}: {{ number(row.voters) }}</p>
            <div v-if="canSetSeats || canDelete" class="mt-2 flex items-center justify-end gap-3">
              <EditIconButton v-if="canSetSeats" @click="openSeats(row)" />
              <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeSeats(row)">{{ t('admin.electionOffices.delete') }}</button>
            </div>
          </div>
        </div>
      </div>

      <DataTable class="hidden sm:block" :columns="seatColumns" :rows="seats.items.value" row-key="id" :loading="seats.loading.value" :empty-message="t('admin.electionOffices.noSeats')">
        <template #cell-commune="{ row }"><span class="block min-w-48 whitespace-normal text-sm text-neutral-800">{{ placeText(row) }}</span></template>
        <template #cell-seats="{ row }"><span class="font-semibold tabular-nums text-primary-700">{{ row.seats }}</span></template>
        <template #cell-offices="{ row }"><span class="tabular-nums">{{ number(row.offices) }}</span></template>
        <template #cell-voters="{ row }"><span class="tabular-nums">{{ number(row.voters) }}</span></template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-3">
            <EditIconButton v-if="canSetSeats" @click="openSeats(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeSeats(row)">{{ t('admin.electionOffices.delete') }}</button>
          </div>
        </template>
      </DataTable>

      <BasePagination v-if="seats.meta.value" :meta="seats.meta.value" sticky class="mt-4" @update:page="seats.setPage" />
    </template>

    <ElectionOfficeFormModal v-model="officeModal" :office="editing" :year="year" :place="place" @saved="reload" />
    <CommuneSeatsModal v-model="seatsModal" :year="year" :place="seatsPlace" :seats="seatsCount" @saved="reload" />
    <ElectionImportModal v-model="importModal" :year="year" @imported="onImported" />
  </div>
</template>

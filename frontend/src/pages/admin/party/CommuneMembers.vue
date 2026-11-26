<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CommuneMemberFormModal from '@/components/admin/CommuneMemberFormModal.vue'
import CommuneMemberImportModal from '@/components/admin/CommuneMemberImportModal.vue'
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
import { communeMembersService, type CommuneMember, type MemberSummary } from '@/services/communeMembers'
import type { PlacePick } from '@/services/partyBranches'
import type { PlaceBrief } from '@/services/partyLocations'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { genderLabel } from '@/utils/gender'

/**
 * Political Party > Commune members: each commune's full members
 * (ពេញសិទ្ធិ) and reserve members (បម្រុង) for an election year, picked
 * from HRM staff. Pick a commune to see its lists in order and how the full
 * members compare with its council seats (a warning only).
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canCreate = computed(() => auth.can('commune-members.create'))
const canUpdate = computed(() => auth.can('commune-members.update'))
const canDelete = computed(() => auth.can('commune-members.delete'))
const canImport = computed(() => canCreate.value && canUpdate.value)
const canManage = computed(() => canUpdate.value || canDelete.value)

const summary = ref<MemberSummary | null>(null)
const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<CommuneMember>(async (query) => {
  const result = await communeMembersService.list(query)
  summary.value = result.summary
  return result
})

const year = ref<number>(new Date().getFullYear())
const years = ref<number[]>([])
const yearOptions = computed(() => [...new Set([...years.value, year.value])].sort((a, b) => b - a).map((y) => ({ value: String(y), label: String(y) })))
const place = ref<PlacePick>({ province_id: '', district_id: '', commune_id: '', village_id: '' })
const role = ref('')
const search = ref('')
const roleOptions = computed(() => [
  { value: '', label: t('admin.communeMembers.allTypes') },
  { value: 'full', label: t('admin.communeMembers.roles.full') },
  { value: 'reserve', label: t('admin.communeMembers.roles.reserve') },
])

function onPlace(next: PlacePick) {
  for (const key of ['province_id', 'district_id', 'commune_id'] as const) {
    if (place.value[key] !== next[key]) setFilter(key, next[key] || undefined)
  }
  place.value = next
}
function onYear(value: string) {
  year.value = Number(value)
  setFilter('election_year', value)
}
function onRole(value: string) {
  role.value = value
  setFilter('role', value || undefined)
}
function onSearch(value: string) {
  search.value = value
  setSearch(value)
}
function reload() {
  void fetch()
  void communeMembersService.years().then((list) => (years.value = list)).catch(() => undefined)
}

const pickedName = computed(() => {
  const brief = summary.value?.place
  return brief ? `${brief.name_km} (${brief.name_latin})` : t('admin.electionOffices.everywhere')
})
/** Full members vs seats differ (only when seats are known). */
const seatsMismatch = computed(() => !!summary.value && summary.value.seats > 0 && summary.value.full !== summary.value.seats)

const placeText = (row: CommuneMember) =>
  [row.commune, row.district, row.province]
    .filter((p): p is PlaceBrief => p !== null)
    .map((p) => `${p.name_km} (${p.name_latin})`)
    .join(' · ')
const villageText = (row: CommuneMember) => (row.staff?.village ? `${row.staff.village.name_km} (${row.staff.village.name_latin})` : '')

const columns = computed(() => [
  { key: 'no', label: t('admin.communeMembers.no') },
  { key: 'name', label: t('admin.communeMembers.name') },
  { key: 'gender', label: t('admin.communeMembers.genderBirth') },
  { key: 'national_id', label: t('admin.communeMembers.idCard') },
  { key: 'phone', label: t('admin.communeMembers.phone') },
  { key: 'village', label: t('admin.communeMembers.village') },
  ...(place.value.commune_id ? [] : [{ key: 'commune', label: t('admin.partyLocations.levels.commune') }]),
  ...(canManage.value ? [{ key: 'actions', label: '', align: 'text-right' }] : []),
])

const formOpen = ref(false)
const editing = ref<CommuneMember | null>(null)
function open(member: CommuneMember | null) {
  editing.value = member
  formOpen.value = true
}
const importOpen = ref(false)
function onImported(importedYear: number) {
  if (importedYear !== year.value) onYear(String(importedYear))
  reload()
}

const actionError = ref<string | null>(null)
async function remove(member: CommuneMember) {
  if (!(await confirmDialog.confirm({ message: t('admin.communeMembers.removeConfirm', { name: member.staff?.name ?? '' }), danger: true }))) return
  actionError.value = null
  try {
    await communeMembersService.remove(member.id)
    reload()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.communeMembers.removeFailed')
  }
}

onMounted(async () => {
  years.value = await communeMembersService.years().catch(() => [])
  if (years.value.length) year.value = years.value[0]
  setFilter('election_year', String(year.value))
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('adminNav.items.communeMembers') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.communeMembers.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <BaseButton v-if="canImport" variant="outline" @click="importOpen = true">{{ t('admin.communeMembers.import') }}</BaseButton>
        <BaseButton v-if="canCreate" @click="open(null)">{{ t('admin.communeMembers.add') }}</BaseButton>
      </div>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <BaseSelect :model-value="String(year)" :options="yearOptions" :label="t('admin.communeMembers.year')" @update:model-value="onYear" />
      <PlaceSelects :model-value="place" :depth="3" :all-label="t('admin.partyLocations.all')" @update:model-value="onPlace" />
    </div>

    <!-- The pick's members against its council seats. -->
    <div class="mb-4 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
      <p class="text-sm text-neutral-500">{{ pickedName }} · {{ year }}</p>
      <div class="mt-3 grid grid-cols-3 gap-3">
        <div>
          <p class="text-xs text-neutral-500">{{ t('admin.communeMembers.roles.full') }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-primary-700">
            {{ summary?.full ?? 0 }}<span v-if="summary?.seats" class="text-base font-normal text-neutral-500"> / {{ summary.seats }}</span>
          </p>
          <p v-if="summary?.seats" class="text-xs text-neutral-500">{{ t('admin.communeMembers.ofSeats') }}</p>
        </div>
        <div>
          <p class="text-xs text-neutral-500">{{ t('admin.communeMembers.roles.reserve') }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ summary?.reserve ?? 0 }}</p>
        </div>
        <div>
          <p class="text-xs text-neutral-500">{{ t('admin.communeMembers.communes') }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ summary?.communes ?? 0 }}</p>
        </div>
      </div>
      <BaseAlert v-if="seatsMismatch" variant="warning" class="mt-3">
        {{ place.commune_id ? t('admin.communeMembers.seatsWarning', { full: summary!.full, seats: summary!.seats }) : t('admin.communeMembers.seatsWarningMany', { full: summary!.full, seats: summary!.seats }) }}
      </BaseAlert>
    </div>

    <div class="mb-3 grid gap-3 sm:grid-cols-[1fr_12rem]">
      <input
        type="search"
        :value="search"
        :placeholder="t('admin.communeMembers.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="onSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect :model-value="role" :options="roleOptions" @update:model-value="onRole" />
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.communeMembers.empty') }}</p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start gap-3">
            <img v-if="row.staff?.photo_url" :src="row.staff.photo_url" alt="" class="h-12 w-12 shrink-0 rounded-full object-cover" />
            <div v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-sm font-medium text-neutral-500">{{ row.staff?.name?.charAt(0) ?? '?' }}</div>
            <div class="min-w-0 flex-1">
              <p class="font-medium text-neutral-900">{{ row.staff?.name ?? '—' }}</p>
              <p class="text-xs text-neutral-500">{{ row.staff?.employee_code }}<template v-if="row.staff?.other_name"> · {{ row.staff.other_name }}</template></p>
              <p class="mt-1 text-xs text-neutral-600">{{ genderLabel(row.staff?.gender) }}<template v-if="row.staff?.date_of_birth"> · {{ formatDate(row.staff.date_of_birth) }}</template><template v-if="row.staff?.phone"> · {{ row.staff.phone }}</template></p>
              <p v-if="row.staff?.national_id" class="text-xs text-neutral-600">{{ t('admin.communeMembers.idCard') }}: {{ row.staff.national_id }}</p>
              <p v-if="villageText(row)" class="text-xs text-neutral-500">{{ villageText(row) }}</p>
              <p v-if="!place.commune_id" class="text-xs text-neutral-500">{{ placeText(row) }}</p>
            </div>
            <div class="text-right">
              <BaseBadge :variant="row.role === 'full' ? 'success' : 'neutral'">{{ t(`admin.communeMembers.roles.${row.role}`) }} {{ row.position }}</BaseBadge>
              <BaseBadge v-if="row.staff?.left" variant="warning" class="mt-1">{{ t('admin.communeMembers.left') }}</BaseBadge>
            </div>
          </div>
          <div v-if="canManage" class="mt-2 flex items-center justify-end gap-3">
            <EditIconButton v-if="canUpdate" @click="open(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.communeMembers.remove') }}</button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.communeMembers.empty')">
      <template #cell-no="{ row }">
        <BaseBadge :variant="row.role === 'full' ? 'success' : 'neutral'" class="whitespace-nowrap">{{ t(`admin.communeMembers.roles.${row.role}`) }} {{ row.position }}</BaseBadge>
      </template>
      <template #cell-name="{ row }">
        <div class="flex items-center gap-3">
          <img v-if="row.staff?.photo_url" :src="row.staff.photo_url" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover" />
          <div v-else class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-medium text-neutral-500">{{ row.staff?.name?.charAt(0) ?? '?' }}</div>
          <div class="min-w-0">
            <p class="font-medium text-neutral-900">{{ row.staff?.name ?? '—' }}</p>
            <p class="text-xs text-neutral-500">
              {{ row.staff?.employee_code }}<template v-if="row.staff?.position"> · {{ row.staff.position }}</template>
              <BaseBadge v-if="row.staff?.left" variant="warning" class="ml-1">{{ t('admin.communeMembers.left') }}</BaseBadge>
            </p>
          </div>
        </div>
      </template>
      <template #cell-gender="{ row }">
        <p>{{ genderLabel(row.staff?.gender) }}</p>
        <p class="text-xs tabular-nums text-neutral-500">{{ row.staff?.date_of_birth ? formatDate(row.staff.date_of_birth) : '' }}</p>
      </template>
      <template #cell-national_id="{ row }"><span class="tabular-nums">{{ row.staff?.national_id ?? '—' }}</span></template>
      <template #cell-phone="{ row }"><span class="whitespace-nowrap tabular-nums">{{ row.staff?.phone ?? '—' }}</span></template>
      <template #cell-village="{ row }"><span class="text-sm text-neutral-700">{{ villageText(row) || '—' }}</span></template>
      <template #cell-commune="{ row }"><span class="block min-w-40 max-w-xs whitespace-normal text-sm text-neutral-700">{{ placeText(row) }}</span></template>
      <template #cell-actions="{ row }">
        <div class="flex items-center justify-end gap-3">
          <EditIconButton v-if="canUpdate" @click="open(row)" />
          <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.communeMembers.remove') }}</button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CommuneMemberFormModal v-model="formOpen" :member="editing" :year="year" :place="place" @saved="reload" />
    <CommuneMemberImportModal v-model="importOpen" :year="year" @imported="onImported" />
  </div>
</template>

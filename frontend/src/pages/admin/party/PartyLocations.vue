<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { geographyService, type GeographyOption } from '@/services/geography'
import { partyLocationsService, type Place, type PlaceBrief, type PlaceLevel } from '@/services/partyLocations'
import { ApiRequestError, type LengthAwarePaginationMeta } from '@/types/api'

/**
 * Political Party > Locations: Cambodia's provinces, districts, communes and
 * villages. Province → District → Commune narrow each other (each list can be
 * typed into); the search box finds any place under them by Khmer name, Latin
 * name or code. Opening a place lists the level under it.
 */
const { t } = useI18n()

const provinces = ref<GeographyOption[]>([])
const districts = ref<GeographyOption[]>([])
const communes = ref<GeographyOption[]>([])
const provinceId = ref('')
const districtId = ref('')
const communeId = ref('')
const level = ref<PlaceLevel | ''>('')
const search = ref('')

const options = (rows: GeographyOption[]) => [
  { value: '', label: t('admin.partyLocations.all') },
  ...rows.map((row) => ({ value: String(row.id), label: `${row.name_km} — ${row.name_latin}` })),
]
const levels: PlaceLevel[] = ['province', 'district', 'commune', 'village']
const levelOptions = computed(() => [
  { value: '', label: t('admin.partyLocations.allLevels') },
  ...levels.map((value) => ({ value, label: t(`admin.partyLocations.levels.${value}`) })),
])

const rows = ref<Place[]>([])
const meta = ref<LengthAwarePaginationMeta | null>(null)
const page = ref(1)
const loading = ref(false)
const error = ref<string | null>(null)

let latest = 0
async function load() {
  const ticket = ++latest
  loading.value = true
  error.value = null
  try {
    const result = await partyLocationsService.list({
      page: page.value,
      per_page: 25,
      province_id: provinceId.value ? Number(provinceId.value) : null,
      district_id: districtId.value ? Number(districtId.value) : null,
      commune_id: communeId.value ? Number(communeId.value) : null,
      level: level.value,
      search: search.value.trim(),
    })
    if (ticket !== latest) return
    rows.value = result.data
    meta.value = result.pagination as LengthAwarePaginationMeta
  } catch (e) {
    if (ticket === latest) error.value = e instanceof ApiRequestError ? e.message : t('admin.partyLocations.loadFailed')
  } finally {
    if (ticket === latest) loading.value = false
  }
}

function reload() {
  page.value = 1
  void load()
}

async function onProvince(value: string) {
  provinceId.value = value
  districtId.value = ''
  communeId.value = ''
  communes.value = []
  districts.value = value ? await geographyService.districts(Number(value)).catch(() => []) : []
  reload()
}
async function onDistrict(value: string) {
  districtId.value = value
  communeId.value = ''
  communes.value = value ? await geographyService.communes(Number(value)).catch(() => []) : []
  reload()
}
function onCommune(value: string) {
  communeId.value = value
  reload()
}

let timer: ReturnType<typeof setTimeout> | undefined
function onSearch(value: string) {
  search.value = value
  clearTimeout(timer)
  timer = setTimeout(reload, 300)
}
function onLevel(value: string) {
  level.value = value as PlaceLevel | ''
  reload()
}

function setPage(next: number) {
  page.value = next
  void load()
}

function resetFilters() {
  clearTimeout(timer)
  search.value = ''
  level.value = ''
}

function clearAll() {
  resetFilters()
  void onProvince('')
}

/** Open a place: pick it in the lists above, so the list shows what sits under it. */
async function open(place: Place) {
  if (place.level === 'village') return
  resetFilters()
  const province = place.level === 'province' ? place : place.province
  const district = place.level === 'district' ? place : place.district
  provinceId.value = province ? String(province.id) : ''
  districts.value = province ? await geographyService.districts(province.id).catch(() => []) : []
  districtId.value = district ? String(district.id) : ''
  communes.value = district ? await geographyService.communes(district.id).catch(() => []) : []
  communeId.value = place.level === 'commune' ? String(place.id) : ''
  reload()
}

const names = (place: PlaceBrief) => `${place.name_km} (${place.name_latin})`
function path(place: Place): string {
  return [place.commune, place.district, place.province]
    .filter((p): p is PlaceBrief => p !== null)
    .map(names)
    .join(' · ')
}

onMounted(async () => {
  provinces.value = await geographyService.provinces().catch(() => [])
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-lg font-semibold text-neutral-900">{{ t('adminNav.items.partyLocations') }}</h1>
      <p class="text-sm text-neutral-500">{{ t('admin.partyLocations.intro') }}</p>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <SearchableSelect :model-value="provinceId" :options="options(provinces)" :label="t('admin.partyLocations.levels.province')" @update:model-value="onProvince" />
      <SearchableSelect :model-value="districtId" :options="options(districts)" :label="t('admin.partyLocations.levels.district')" :disabled="!provinceId" @update:model-value="onDistrict" />
      <SearchableSelect :model-value="communeId" :options="options(communes)" :label="t('admin.partyLocations.levels.commune')" :disabled="!districtId" @update:model-value="onCommune" />
      <BaseInput :model-value="search" type="search" :label="t('admin.partyLocations.search')" :placeholder="t('admin.partyLocations.searchPlaceholder')" @update:model-value="onSearch" />
      <BaseSelect :model-value="level" :options="levelOptions" :label="t('admin.partyLocations.level')" @update:model-value="onLevel" />
      <div class="flex items-end">
        <BaseButton variant="outline" @click="clearAll">{{ t('admin.partyLocations.clear') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <p v-if="meta" class="mb-2 text-sm text-neutral-500">{{ t('admin.partyLocations.found', { n: meta.total }) }}</p>
    <div v-if="loading && rows.length === 0" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="rows.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.partyLocations.none') }}</p>

    <template v-else>
      <!-- Phone: one card per place. -->
      <div class="space-y-2 sm:hidden" :class="{ 'opacity-60': loading }">
        <div v-for="place in rows" :key="`${place.level}-${place.id}`" class="rounded-lg border border-neutral-200 bg-white p-3">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-medium text-neutral-900">{{ place.name_km }}</p>
              <p class="text-sm text-neutral-600">{{ place.name_latin }}</p>
            </div>
            <span class="shrink-0 rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-700">{{ t(`admin.partyLocations.levels.${place.level}`) }}</span>
          </div>
          <p class="mt-1 text-xs text-neutral-500">{{ t('admin.partyLocations.code') }}: <span class="tabular-nums">{{ place.code }}</span></p>
          <p v-if="path(place)" class="mt-0.5 text-xs text-neutral-500">{{ path(place) }}</p>
          <button v-if="place.level !== 'village'" type="button" class="mt-2 text-sm font-medium text-primary-600" @click="open(place)">
            {{ t('admin.partyLocations.openN', { n: place.children }) }}
          </button>
        </div>
      </div>

      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block" :class="{ 'opacity-60': loading }">
        <table class="min-w-full text-sm">
          <thead class="bg-neutral-50">
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
              <th class="px-3 py-2 font-medium">{{ t('admin.partyLocations.code') }}</th>
              <th class="px-3 py-2 font-medium">{{ t('admin.partyLocations.nameKm') }}</th>
              <th class="px-3 py-2 font-medium">{{ t('admin.partyLocations.nameLatin') }}</th>
              <th class="px-3 py-2 font-medium">{{ t('admin.partyLocations.level') }}</th>
              <th class="px-3 py-2 font-medium">{{ t('admin.partyLocations.path') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="place in rows" :key="`${place.level}-${place.id}`" class="border-t border-neutral-100">
              <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ place.code }}</td>
              <td class="px-3 py-2 font-medium text-neutral-900">{{ place.name_km }}</td>
              <td class="px-3 py-2">{{ place.name_latin }}</td>
              <td class="whitespace-nowrap px-3 py-2">{{ t(`admin.partyLocations.levels.${place.level}`) }}</td>
              <td class="px-3 py-2 text-neutral-600">{{ path(place) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right">
                <button v-if="place.level !== 'village'" type="button" class="font-medium text-primary-600 hover:text-primary-700" @click="open(place)">
                  {{ t('admin.partyLocations.openN', { n: place.children }) }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <BasePagination v-if="meta && meta.last_page > 1" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { geographyService, type GeographyOption } from '@/services/geography'
import type { PlacePick } from '@/services/partyBranches'

/**
 * Province → District → Commune → Village lists that narrow each other
 * (type into any of them to find a name). `depth` is how many levels show
 * (1 = province only … 4 = down to the village). With `allLabel` each list
 * starts with that choice (a filter); without it a list is a required pick
 * (a form); `optionalFrom` (a depth) makes that level and the ones under it
 * optional, with a "—" choice. Picking a level clears every level under it.
 */
const props = withDefaults(defineProps<{ modelValue: PlacePick; depth?: number; allLabel?: string; errors?: Partial<Record<keyof PlacePick, string>>; required?: boolean; optionalFrom?: number }>(), {
  depth: 4,
  allLabel: undefined,
  errors: () => ({}),
  required: false,
  optionalFrom: 99,
})
const emit = defineEmits<{ 'update:modelValue': [value: PlacePick] }>()

const { t } = useI18n()

const provinces = ref<GeographyOption[]>([])
const districts = ref<GeographyOption[]>([])
const communes = ref<GeographyOption[]>([])
const villages = ref<GeographyOption[]>([])

const options = (rows: GeographyOption[], depth = 1) => [
  ...(props.allLabel ? [{ value: '', label: props.allLabel }] : depth >= props.optionalFrom ? [{ value: '', label: '—' }] : []),
  ...rows.map((row) => ({ value: String(row.id), label: `${row.name_km} — ${row.name_latin}` })),
]
const lists = computed(() => [
  { key: 'province_id' as const, label: t('admin.partyLocations.levels.province'), options: options(provinces.value, 1), disabled: false },
  { key: 'district_id' as const, label: t('admin.partyLocations.levels.district'), options: options(districts.value, 2), disabled: !props.modelValue.province_id },
  { key: 'commune_id' as const, label: t('admin.partyLocations.levels.commune'), options: options(communes.value, 3), disabled: !props.modelValue.district_id },
  { key: 'village_id' as const, label: t('admin.partyLocations.levels.village'), options: options(villages.value, 4), disabled: !props.modelValue.commune_id },
].slice(0, props.depth))

// Load each list for the ids already picked (also when a form opens on a saved place).
const loaded: Record<string, string> = {}
async function fill(key: 'districts' | 'communes' | 'villages', parent: string) {
  if (loaded[key] === parent) return
  loaded[key] = parent
  const target = key === 'districts' ? districts : key === 'communes' ? communes : villages
  if (!parent) {
    target.value = []
    return
  }
  const id = Number(parent)
  const rows = await (key === 'districts' ? geographyService.districts(id) : key === 'communes' ? geographyService.communes(id) : geographyService.villages(id)).catch(() => [])
  if (loaded[key] === parent) target.value = rows
}
watch(
  () => [props.modelValue.province_id, props.modelValue.district_id, props.modelValue.commune_id, props.depth] as const,
  ([province, district, commune, depth]) => {
    if (depth > 1) void fill('districts', province)
    if (depth > 2) void fill('communes', district)
    if (depth > 3) void fill('villages', commune)
  },
  { immediate: true },
)
void geographyService.provinces().then((rows) => (provinces.value = rows)).catch(() => [])

const order: (keyof PlacePick)[] = ['province_id', 'district_id', 'commune_id', 'village_id']
function pick(key: keyof PlacePick, value: string) {
  const next = { ...props.modelValue, [key]: value }
  for (const below of order.slice(order.indexOf(key) + 1)) next[below] = ''
  emit('update:modelValue', next)
}
</script>

<template>
  <SearchableSelect
    v-for="(list, index) in lists"
    :key="list.key"
    :model-value="modelValue[list.key]"
    :options="list.options"
    :label="list.label"
    :required="required && index + 1 < optionalFrom"
    :disabled="list.disabled"
    :error="errors[list.key]"
    @update:model-value="pick(list.key, $event)"
  />
</template>

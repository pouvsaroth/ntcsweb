<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseSelect from '@/components/ui/BaseSelect.vue'
import { geographyService, type GeographyOption } from '@/services/geography'

/**
 * Cambodia's Province > District > Commune > Village hierarchy, as four
 * cascading selects that together resolve to one `village_code` — the same
 * logic StudentForm.vue/StaffForm.vue/Register.vue each carry inline; this
 * is the shared version for any new form (e.g. the Exam Application form)
 * so it isn't copy-pasted a fourth time. Selecting a level resets everything
 * below it; only picking a village actually sets the model value.
 */
const props = defineProps<{ modelValue: string; disabled?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { t } = useI18n()

const provinces = ref<GeographyOption[]>([])
const districts = ref<GeographyOption[]>([])
const communes = ref<GeographyOption[]>([])
const villages = ref<GeographyOption[]>([])

const selectedProvinceId = ref('')
const selectedDistrictId = ref('')
const selectedCommuneId = ref('')
const selectedVillageId = ref('')

function toOptions(rows: GeographyOption[]) {
  return rows.map((row) => ({ value: String(row.id), label: `${row.name_km} — ${row.name_latin}` }))
}

const provinceOptions = computed(() => toOptions(provinces.value))
const districtOptions = computed(() => toOptions(districts.value))
const communeOptions = computed(() => toOptions(communes.value))
const villageOptions = computed(() => toOptions(villages.value))

async function onProvinceChange(value: string) {
  selectedProvinceId.value = value
  selectedDistrictId.value = ''
  selectedCommuneId.value = ''
  selectedVillageId.value = ''
  districts.value = []
  communes.value = []
  villages.value = []
  emit('update:modelValue', '')

  if (value) districts.value = await geographyService.districts(Number(value))
}

async function onDistrictChange(value: string) {
  selectedDistrictId.value = value
  selectedCommuneId.value = ''
  selectedVillageId.value = ''
  communes.value = []
  villages.value = []
  emit('update:modelValue', '')

  if (value) communes.value = await geographyService.communes(Number(value))
}

async function onCommuneChange(value: string) {
  selectedCommuneId.value = value
  selectedVillageId.value = ''
  villages.value = []
  emit('update:modelValue', '')

  if (value) villages.value = await geographyService.villages(Number(value))
}

function onVillageChange(value: string) {
  selectedVillageId.value = value
  emit('update:modelValue', villages.value.find((v) => String(v.id) === value)?.code ?? '')
}

/** Pre-selects all four dropdowns for a village_code that already exists. */
async function selectFromVillageCode(code: string) {
  const ancestry = await geographyService.lookup(code)

  selectedProvinceId.value = String(ancestry.province.id)
  districts.value = await geographyService.districts(ancestry.province.id)

  selectedDistrictId.value = String(ancestry.district.id)
  communes.value = await geographyService.communes(ancestry.district.id)

  selectedCommuneId.value = String(ancestry.commune.id)
  villages.value = await geographyService.villages(ancestry.commune.id)

  selectedVillageId.value = String(ancestry.village.id)
}

watch(
  () => props.modelValue,
  (code) => {
    // Only react to a code arriving from *outside* (e.g. a fresh lookup) —
    // our own onVillageChange() already set the selects, so re-resolving
    // here would just repeat the same four requests pointlessly.
    if (code && selectedVillageId.value === '') void selectFromVillageCode(code)
    if (!code) {
      selectedProvinceId.value = ''
      selectedDistrictId.value = ''
      selectedCommuneId.value = ''
      selectedVillageId.value = ''
      districts.value = []
      communes.value = []
      villages.value = []
    }
  },
  { immediate: true },
)

onMounted(async () => {
  provinces.value = await geographyService.provinces()
})
</script>

<template>
  <BaseSelect
    :model-value="selectedProvinceId"
    :options="provinceOptions"
    :disabled="disabled"
    :label="t('admin.exams.province')"
    @update:model-value="onProvinceChange"
  />
  <BaseSelect
    :model-value="selectedCommuneId"
    :options="communeOptions"
    :disabled="disabled || !selectedDistrictId"
    :label="t('admin.exams.commune')"
    @update:model-value="onCommuneChange"
  />
  <BaseSelect
    :model-value="selectedDistrictId"
    :options="districtOptions"
    :disabled="disabled || !selectedProvinceId"
    :label="t('admin.exams.district')"
    @update:model-value="onDistrictChange"
  />
  <BaseSelect
    :model-value="selectedVillageId"
    :options="villageOptions"
    :disabled="disabled || !selectedCommuneId"
    :label="t('admin.exams.village')"
    @update:model-value="onVillageChange"
  />
</template>

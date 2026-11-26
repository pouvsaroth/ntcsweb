<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import PlaceSelects from '@/components/admin/PlaceSelects.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { electionOfficesService, type ElectionOffice } from '@/services/electionOffices'
import type { PlacePick } from '@/services/partyBranches'
import { ApiRequestError } from '@/types/api'

/**
 * Political Party > Election offices: add or edit one office. It sits in a
 * commune (Province → District → Commune required), the village is optional.
 * A new office starts in the year and place the list is showing.
 */
const props = defineProps<{ modelValue: boolean; office?: ElectionOffice | null; year: number; place: PlacePick }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const form = reactive({ election_year: '', code: '', name: '', location: '', voters: '' })
const place = ref<PlacePick>({ province_id: '', district_id: '', commune_id: '', village_id: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const o = props.office
    Object.assign(form, {
      election_year: String(o?.election_year ?? props.year),
      code: o?.code ?? '',
      name: o?.name ?? '',
      location: o?.location ?? '',
      voters: o?.voters == null ? '' : String(o.voters),
    })
    place.value = o
      ? {
          province_id: o.province ? String(o.province.id) : '',
          district_id: o.district ? String(o.district.id) : '',
          commune_id: o.commune ? String(o.commune.id) : '',
          village_id: o.village ? String(o.village.id) : '',
        }
      : { ...props.place }
    errors.value = {}
    saveError.value = null
  },
  { immediate: true },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    election_year: Number(form.election_year),
    code: form.code.trim(),
    name: form.name.trim(),
    commune_id: Number(place.value.commune_id),
    village_id: place.value.village_id ? Number(place.value.village_id) : null,
    location: form.location.trim() || null,
    voters: form.voters === '' ? null : Number(form.voters),
  }
  try {
    if (props.office) await electionOfficesService.update(props.office.id, input)
    else await electionOfficesService.create(input)
    emit('saved')
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.electionOffices.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="office ? t('admin.electionOffices.editOffice') : t('admin.electionOffices.addOffice')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.election_year" type="number" required :label="t('admin.electionOffices.year')" :error="errors.election_year?.[0]" />
        <BaseInput v-model="form.code" required :label="t('admin.electionOffices.code')" :error="errors.code?.[0]" />
      </div>
      <BaseInput v-model="form.name" required :label="t('admin.electionOffices.name')" :error="errors.name?.[0]" />
      <div class="grid gap-4 sm:grid-cols-2">
        <PlaceSelects v-model="place" :depth="4" required :optional-from="4" :errors="{ commune_id: errors.commune_id?.[0], village_id: errors.village_id?.[0] }" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.location" :label="t('admin.electionOffices.location')" :error="errors.location?.[0]" />
        <BaseInput v-model="form.voters" type="number" min="0" :label="t('admin.electionOffices.voters')" :error="errors.voters?.[0]" />
      </div>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!form.code.trim() || !form.name.trim() || !place.commune_id || !form.election_year" @click="save">{{ t('admin.electionOffices.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

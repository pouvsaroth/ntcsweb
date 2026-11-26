<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import PlaceSelects from '@/components/admin/PlaceSelects.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { electionOfficesService } from '@/services/electionOffices'
import type { PlacePick } from '@/services/partyBranches'
import { ApiRequestError } from '@/types/api'

/** Political Party > Election offices: set a commune's council seats for an election year (adds or changes it). */
const props = defineProps<{ modelValue: boolean; year: number; place: PlacePick; seats?: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const year = ref('')
const count = ref('')
const place = ref<PlacePick>({ province_id: '', district_id: '', commune_id: '', village_id: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    year.value = String(props.year)
    count.value = props.seats ? String(props.seats) : ''
    place.value = { ...props.place, village_id: '' }
    errors.value = {}
    saveError.value = null
  },
  { immediate: true },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    await electionOfficesService.saveSeats({ election_year: Number(year.value), commune_id: Number(place.value.commune_id), seats: Number(count.value) })
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
  <BaseModal :model-value="modelValue" :title="t('admin.electionOffices.setSeats')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <BaseInput v-model="year" type="number" required :label="t('admin.electionOffices.year')" :error="errors.election_year?.[0]" />
      <div class="grid gap-4 sm:grid-cols-3">
        <PlaceSelects v-model="place" :depth="3" required :errors="{ commune_id: errors.commune_id?.[0] }" />
      </div>
      <BaseInput v-model="count" type="number" min="1" required :label="t('admin.electionOffices.seats')" :error="errors.seats?.[0]" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!place.commune_id || !count || !year" @click="save">{{ t('admin.electionOffices.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import PlaceSelects from '@/components/admin/PlaceSelects.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { partyBranchesService, type PartyBranch, type PlacePick } from '@/services/partyBranches'
import type { PlaceLevel } from '@/services/partyLocations'
import { ApiRequestError } from '@/types/api'

/**
 * Political Party > Branches: add or edit a branch. Level says where it
 * sits (a province, district, commune or village branch); the place lists
 * go down to that level, and the place picked there is the branch's place.
 */
const props = defineProps<{ modelValue: boolean; branch?: PartyBranch | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [branch: PartyBranch] }>()

const { t } = useI18n()

const LEVELS: PlaceLevel[] = ['province', 'district', 'commune', 'village']
const levelOptions = computed(() => LEVELS.map((value) => ({ value, label: t(`admin.partyBranches.levelBranch.${value}`) })))

const form = reactive({ name: '', level: 'province' as PlaceLevel, leader_name: '', leader_phone: '', address: '', opened_on: '', is_active: true, remark: '' })
const place = ref<PlacePick>({ province_id: '', district_id: '', commune_id: '', village_id: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const depth = computed(() => LEVELS.indexOf(form.level) + 1)
const locationId = computed(() => place.value[`${form.level}_id` as keyof PlacePick])

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const b = props.branch
    Object.assign(form, {
      name: b?.name ?? '',
      level: b?.level ?? 'province',
      leader_name: b?.leader_name ?? '',
      leader_phone: b?.leader_phone ?? '',
      address: b?.address ?? '',
      opened_on: b?.opened_on ?? '',
      is_active: b?.is_active ?? true,
      remark: b?.remark ?? '',
    })
    place.value = {
      province_id: b?.province ? String(b.province.id) : '',
      district_id: b?.district ? String(b.district.id) : '',
      commune_id: b?.commune ? String(b.commune.id) : '',
      village_id: b?.village ? String(b.village.id) : '',
    }
    errors.value = {}
    saveError.value = null
  },
  { immediate: true },
)

// A higher level drops the places under it.
watch(
  () => form.level,
  (level) => {
    const keep = LEVELS.indexOf(level)
    const keys: (keyof PlacePick)[] = ['province_id', 'district_id', 'commune_id', 'village_id']
    const next = { ...place.value }
    keys.forEach((key, i) => {
      if (i > keep) next[key] = ''
    })
    place.value = next
  },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    name: form.name.trim(),
    level: form.level,
    location_id: Number(locationId.value),
    leader_name: form.leader_name.trim() || null,
    leader_phone: form.leader_phone.trim() || null,
    address: form.address.trim() || null,
    opened_on: form.opened_on || null,
    is_active: form.is_active,
    remark: form.remark.trim() || null,
  }
  try {
    const saved = props.branch ? await partyBranchesService.update(props.branch.id, input) : await partyBranchesService.create(input)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.partyBranches.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="branch ? t('admin.partyBranches.edit') : t('admin.partyBranches.add')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <p v-if="branch?.code" class="text-sm text-neutral-500">{{ t('admin.partyBranches.code') }}: <span class="font-medium tabular-nums text-neutral-800">{{ branch.code }}</span></p>
      <BaseInput v-model="form.name" required :label="t('admin.partyBranches.name')" :error="errors.name?.[0]" />
      <BaseSelect v-model="form.level" :options="levelOptions" required :label="t('admin.partyBranches.level')" :error="errors.level?.[0]" />
      <div class="grid gap-4 sm:grid-cols-2">
        <PlaceSelects v-model="place" :depth="depth" required :errors="{ [`${form.level}_id`]: errors.location_id?.[0] }" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.leader_name" :label="t('admin.partyBranches.leader')" :error="errors.leader_name?.[0]" />
        <BaseInput v-model="form.leader_phone" :label="t('admin.partyBranches.leaderPhone')" :error="errors.leader_phone?.[0]" />
      </div>
      <BaseInput v-model="form.address" :label="t('admin.partyBranches.address')" :error="errors.address?.[0]" />
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.opened_on" type="date" :label="t('admin.partyBranches.openedOn')" :error="errors.opened_on?.[0]" />
        <label class="flex items-center gap-2 self-end pb-2 text-sm text-neutral-700">
          <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300" />
          {{ t('admin.partyBranches.active') }}
        </label>
      </div>
      <BaseInput v-model="form.remark" :label="t('admin.partyBranches.remark')" :error="errors.remark?.[0]" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!form.name.trim() || !locationId" @click="save">{{ t('admin.partyBranches.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

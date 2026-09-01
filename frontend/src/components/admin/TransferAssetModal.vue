<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { assetLocationsService, type AssetLocation } from '@/services/assetLocations'
import { departmentsService, type Department } from '@/services/departments'

const props = defineProps<{
  modelValue: boolean
  currentLocationId?: number | null
  currentDepartmentId?: number | null
  submitting?: boolean
  error?: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  confirm: [payload: { to_location_id: number | null; to_department_id: number | null; reason?: string }]
}>()

const { t } = useI18n()

const toLocationId = ref('')
const toDepartmentId = ref('')
const reason = ref('')

const locations = ref<AssetLocation[]>([])
const departments = ref<Department[]>([])

const locationOptions = computed(() => [{ value: '', label: t('admin.assets.noLocation') }, ...locations.value.map((l) => ({ value: String(l.id), label: `${l.code} — ${l.name}` }))])
const departmentOptions = computed(() => [{ value: '', label: t('admin.assets.noDepartment') }, ...departments.value.map((d) => ({ value: String(d.id), label: d.name }))])

onMounted(async () => {
  ;[locations.value, departments.value] = await Promise.all([assetLocationsService.listAll(), departmentsService.listAll()])
})

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    toLocationId.value = props.currentLocationId ? String(props.currentLocationId) : ''
    toDepartmentId.value = props.currentDepartmentId ? String(props.currentDepartmentId) : ''
    reason.value = ''
  },
)

function submit() {
  emit('confirm', {
    to_location_id: toLocationId.value ? Number(toLocationId.value) : null,
    to_department_id: toDepartmentId.value ? Number(toDepartmentId.value) : null,
    reason: reason.value || undefined,
  })
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.assets.transferTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseSelect v-model="toLocationId" :options="locationOptions" :label="t('admin.assets.toLocation')" />
      <BaseSelect v-model="toDepartmentId" :options="departmentOptions" :label="t('admin.assets.toDepartment')" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.assets.reason') }}</label>
        <textarea
          v-model="reason"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('admin.assets.transfer') }}</BaseButton>
    </template>
  </BaseModal>
</template>

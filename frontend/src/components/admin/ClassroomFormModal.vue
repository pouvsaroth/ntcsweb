<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { type Building, buildingsService } from '@/services/buildings'
import { type Classroom, classroomsService } from '@/services/classrooms'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  classroom?: Classroom | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.classroom != null)

const buildings = ref<Building[]>([])

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.classrooms.statusActive') },
  { value: 'inactive', label: t('admin.classrooms.statusInactive') },
])

const buildingOptions = computed(() => [
  { value: '', label: t('admin.classrooms.noBuilding') },
  ...buildings.value.map((building) => ({ value: String(building.id), label: building.name })),
])

const form = reactive({
  name: '',
  code: '',
  capacity: null as number | null,
  location: '',
  building_id: null as number | null,
  status: 'active' as 'active' | 'inactive',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.classroom] as const,
  async ([open]) => {
    if (!open) return

    if (buildings.value.length === 0) {
      buildings.value = await buildingsService.listAll()
    }

    form.name = props.classroom?.name ?? ''
    form.code = props.classroom?.code ?? ''
    form.capacity = props.classroom?.capacity ?? null
    form.location = props.classroom?.location ?? ''
    form.building_id = props.classroom?.building_id ?? null
    form.status = props.classroom?.status ?? 'active'
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    if (isEditing.value) {
      await classroomsService.update(props.classroom!.id, { ...form })
    } else {
      await classroomsService.create({ ...form })
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.classrooms.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.classrooms.editTitle') : t('admin.classrooms.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.classrooms.name')" :error="errors.name?.[0]" />
      <BaseInput v-model="form.code" :label="t('admin.classrooms.code')" :error="errors.code?.[0]" />
      <BaseInput
        :model-value="form.capacity !== null ? String(form.capacity) : ''"
        type="number"
        :label="t('admin.classrooms.capacity')"
        :error="errors.capacity?.[0]"
        @update:model-value="form.capacity = $event ? Number($event) : null"
      />
      <BaseSelect
        :model-value="form.building_id !== null ? String(form.building_id) : ''"
        :options="buildingOptions"
        :label="t('admin.classrooms.building')"
        :error="errors.building_id?.[0]"
        @update:model-value="form.building_id = $event ? Number($event) : null"
      />
      <BaseInput v-model="form.location" :label="t('admin.classrooms.location')" :hint="t('admin.classrooms.locationHint')" :error="errors.location?.[0]" />
      <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.classrooms.status')" />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

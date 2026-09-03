<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { type Building, buildingsService } from '@/services/buildings'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  building?: Building | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.building != null)

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.buildings.statusActive') },
  { value: 'inactive', label: t('admin.buildings.statusInactive') },
])

const form = reactive({
  name: '',
  code: '',
  address: '',
  status: 'active' as 'active' | 'inactive',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.building] as const,
  ([open]) => {
    if (!open) return

    form.name = props.building?.name ?? ''
    form.code = props.building?.code ?? ''
    form.address = props.building?.address ?? ''
    form.status = props.building?.status ?? 'active'
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
      await buildingsService.update(props.building!.id, { ...form })
    } else {
      await buildingsService.create({ ...form })
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.buildings.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.buildings.editTitle') : t('admin.buildings.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.buildings.name')" :error="errors.name?.[0]" />
      <BaseInput v-model="form.code" :label="t('admin.buildings.code')" :error="errors.code?.[0]" />
      <BaseInput v-model="form.address" :label="t('admin.buildings.address')" :error="errors.address?.[0]" />
      <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.buildings.status')" />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

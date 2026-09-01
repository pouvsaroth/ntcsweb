<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { assetLocationsService, assetLocationTypes, type AssetLocation } from '@/services/assetLocations'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  location?: AssetLocation | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.location != null)

const form = reactive({
  code: '',
  name: '',
  type: 'ROOM',
  parent_id: '',
  is_active: true,
})

const locations = ref<AssetLocation[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const typeOptions = computed(() =>
  assetLocationTypes.map((type) => ({ value: type, label: t(`admin.assetLocations.type${type.charAt(0)}${type.slice(1).toLowerCase()}`) })),
)

const parentOptions = computed(() => [
  { value: '', label: t('admin.assetLocations.noParent') },
  ...locations.value.filter((l) => l.id !== props.location?.id).map((l) => ({ value: String(l.id), label: `${l.code} — ${l.name}` })),
])

onMounted(async () => {
  locations.value = await assetLocationsService.listAll()
})

watch(
  () => [props.modelValue, props.location] as const,
  ([open]) => {
    if (!open) return

    form.code = props.location?.code ?? ''
    form.name = props.location?.name ?? ''
    form.type = props.location?.type ?? 'ROOM'
    form.parent_id = props.location?.parent_id ? String(props.location.parent_id) : ''
    form.is_active = props.location?.is_active ?? true
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
    const input = {
      ...form,
      type: form.type as AssetLocation['type'],
      parent_id: form.parent_id ? Number(form.parent_id) : null,
      classroom_id: props.location?.classroom_id ?? null,
    }

    if (isEditing.value) {
      await assetLocationsService.update(props.location!.id, input)
    } else {
      await assetLocationsService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.assetLocations.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.assetLocations.editTitle') : t('admin.assetLocations.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.code" required :label="t('admin.assetLocations.code')" :error="errors.code?.[0]" />
      <BaseInput v-model="form.name" required :label="t('admin.assetLocations.name')" :error="errors.name?.[0]" />
      <BaseSelect v-model="form.type" :options="typeOptions" :label="t('admin.assetLocations.type')" />
      <BaseSelect v-model="form.parent_id" :options="parentOptions" :label="t('admin.assetLocations.parent')" :error="errors.parent_id?.[0]" />

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.assetLocations.statusActive') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { assetCategoriesService, type AssetCategory } from '@/services/assetCategories'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  category?: AssetCategory | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.category != null)

const form = reactive({
  code: '',
  name: '',
  description: '',
  parent_id: '',
  is_active: true,
})

const categories = ref<AssetCategory[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const parentOptions = computed(() => [
  { value: '', label: t('admin.assetCategories.noParent') },
  ...categories.value.filter((c) => c.id !== props.category?.id).map((c) => ({ value: String(c.id), label: `${c.code} — ${c.name}` })),
])

onMounted(async () => {
  categories.value = await assetCategoriesService.listAll()
})

watch(
  () => [props.modelValue, props.category] as const,
  ([open]) => {
    if (!open) return

    form.code = props.category?.code ?? ''
    form.name = props.category?.name ?? ''
    form.description = props.category?.description ?? ''
    form.parent_id = props.category?.parent_id ? String(props.category.parent_id) : ''
    form.is_active = props.category?.is_active ?? true
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
    const input = { ...form, parent_id: form.parent_id ? Number(form.parent_id) : null }

    if (isEditing.value) {
      await assetCategoriesService.update(props.category!.id, input)
    } else {
      await assetCategoriesService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.assetCategories.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.assetCategories.editTitle') : t('admin.assetCategories.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.code" required :label="t('admin.assetCategories.code')" :error="errors.code?.[0]" />
      <BaseInput v-model="form.name" required :label="t('admin.assetCategories.name')" :error="errors.name?.[0]" />

      <BaseSelect v-model="form.parent_id" :options="parentOptions" :label="t('admin.assetCategories.parent')" :error="errors.parent_id?.[0]" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.assetCategories.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.assetCategories.statusActive') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

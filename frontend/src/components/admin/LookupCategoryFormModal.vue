<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { lookupCategoriesService, type LookupCategory } from '@/services/lookupCategories'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  category?: LookupCategory | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.category != null)

const form = reactive({
  code: '',
  name: '',
  description: '',
  is_active: true,
  sort_order: 0,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.category] as const,
  ([open]) => {
    if (!open) return

    form.code = props.category?.code ?? ''
    form.name = props.category?.name ?? ''
    form.description = props.category?.description ?? ''
    form.is_active = props.category?.is_active ?? true
    form.sort_order = props.category?.sort_order ?? 0
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
      await lookupCategoriesService.update(props.category!.id, form)
    } else {
      await lookupCategoriesService.create(form)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.lookupCategories.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.lookupCategories.editTitle') : t('admin.lookupCategories.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.code" required :label="t('admin.lookupCategories.code')" :hint="t('admin.lookupCategories.codeHint')" :error="errors.code?.[0]" />
      <BaseInput v-model="form.name" required :label="t('admin.lookupCategories.name')" :error="errors.name?.[0]" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.lookupCategories.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.lookupCategories.statusActive') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

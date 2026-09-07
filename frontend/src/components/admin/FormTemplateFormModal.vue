<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { formCategoriesService } from '@/services/formCategories'
import { formTemplatesService, type FormTemplate } from '@/services/formTemplates'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when adding a new template. */
  template?: FormTemplate | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.template != null)

const categoryOptions = ref<{ value: string; label: string }[]>([])

const form = reactive({
  form_category_id: '',
  code: '',
  name: '',
  description: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

async function loadCategories() {
  const result = await formCategoriesService.list()
  categoryOptions.value = result.data.map((category) => ({ value: String(category.id), label: category.name }))
}

watch(
  () => [props.modelValue, props.template] as const,
  ([open]) => {
    if (!open) return

    form.form_category_id = props.template ? String(props.template.form_category_id) : ''
    form.code = props.template?.code ?? ''
    form.name = props.template?.name ?? ''
    form.description = props.template?.description ?? ''
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

onMounted(() => loadCategories())

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input = {
      form_category_id: Number(form.form_category_id),
      code: form.code,
      name: form.name,
      description: form.description || null,
    }

    if (isEditing.value) {
      await formTemplatesService.update(props.template!.id, input)
    } else {
      await formTemplatesService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.formTemplates.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.formTemplates.editTitle') : t('admin.formTemplates.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseSelect
        v-model="form.form_category_id"
        required
        :options="categoryOptions"
        :label="t('admin.formTemplates.category')"
        :placeholder="t('admin.formTemplates.selectCategory')"
        :error="errors.form_category_id?.[0]"
      />

      <BaseInput v-model="form.code" required :label="t('admin.formTemplates.code')" :error="errors.code?.[0]" />

      <BaseInput v-model="form.name" required :label="t('admin.formTemplates.name')" :error="errors.name?.[0]" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.formTemplates.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
        <p v-if="errors.description?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.description[0] }}</p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

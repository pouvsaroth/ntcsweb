<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { languagesService, type Language } from '@/services/languages'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  language?: Language | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.language != null)

const form = reactive({
  code: '',
  name: '',
  native_name: '',
  is_active: true,
  is_default: false,
  sort_order: 0,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.language] as const,
  ([open]) => {
    if (!open) return

    form.code = props.language?.code ?? ''
    form.name = props.language?.name ?? ''
    form.native_name = props.language?.native_name ?? ''
    form.is_active = props.language?.is_active ?? true
    form.is_default = props.language?.is_default ?? false
    form.sort_order = props.language?.sort_order ?? 0
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
      await languagesService.update(props.language!.id, form)
    } else {
      await languagesService.create(form)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.languages.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.languages.editTitle') : t('admin.languages.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.code" required :label="t('admin.languages.code')" :hint="t('admin.languages.codeHint')" :error="errors.code?.[0]" />
      <BaseInput v-model="form.name" required :label="t('admin.languages.name')" :hint="t('admin.languages.nameHint')" :error="errors.name?.[0]" />
      <BaseInput v-model="form.native_name" required :label="t('admin.languages.nativeName')" :hint="t('admin.languages.nativeNameHint')" :error="errors.native_name?.[0]" />

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.languages.statusActive') }}
      </label>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.languages.isDefault') }}
      </label>
      <p class="text-xs text-neutral-500">{{ t('admin.languages.isDefaultHint') }}</p>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

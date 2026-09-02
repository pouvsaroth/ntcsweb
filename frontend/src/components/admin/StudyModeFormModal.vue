<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { studyModesService, type StudyMode } from '@/services/studyModes'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  studyMode?: StudyMode | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.studyMode != null)

const form = reactive({
  code: '',
  name: '',
  is_active: true,
  sort_order: 0,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.studyMode] as const,
  ([open]) => {
    if (!open) return

    form.code = props.studyMode?.code ?? ''
    form.name = props.studyMode?.name ?? ''
    form.is_active = props.studyMode?.is_active ?? true
    form.sort_order = props.studyMode?.sort_order ?? 0
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
      await studyModesService.update(props.studyMode!.id, form)
    } else {
      await studyModesService.create(form)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.studyModes.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.studyModes.editTitle') : t('admin.studyModes.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.code" required :label="t('admin.studyModes.code')" :hint="t('admin.studyModes.codeHint')" :error="errors.code?.[0]" />
      <BaseInput v-model="form.name" required :label="t('admin.studyModes.name')" :error="errors.name?.[0]" />

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.studyModes.statusActive') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

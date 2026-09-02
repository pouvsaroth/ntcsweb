<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { academicYearsService, type AcademicYear } from '@/services/academicYears'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  academicYear?: AcademicYear | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.academicYear != null)

const form = reactive({
  name: '',
  start_date: '',
  end_date: '',
  is_current: false,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.academicYear] as const,
  ([open]) => {
    if (!open) return

    form.name = props.academicYear?.name ?? ''
    form.start_date = props.academicYear?.start_date ?? ''
    form.end_date = props.academicYear?.end_date ?? ''
    form.is_current = props.academicYear?.is_current ?? false
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
      await academicYearsService.update(props.academicYear!.id, form)
    } else {
      await academicYearsService.create(form)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.academicYears.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.academicYears.editTitle') : t('admin.academicYears.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.academicYears.name')" :hint="t('admin.academicYears.nameHint')" :error="errors.name?.[0]" />

      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.start_date" type="date" :label="t('admin.academicYears.startDate')" :error="errors.start_date?.[0]" />
        <BaseInput v-model="form.end_date" type="date" :label="t('admin.academicYears.endDate')" :error="errors.end_date?.[0]" />
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_current" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.academicYears.isCurrent') }}
      </label>
      <p class="text-xs text-neutral-500">{{ t('admin.academicYears.isCurrentHint') }}</p>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

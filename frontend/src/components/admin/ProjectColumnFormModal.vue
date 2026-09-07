<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { projectColumnsService, type ProjectColumn } from '@/services/projects'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  projectId: number
  /** Present when editing; absent when adding a new column. */
  column?: ProjectColumn | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [column: ProjectColumn] }>()

const { t } = useI18n()

const isEditing = computed(() => props.column != null)

const palette = ['#94a3b8', '#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#a855f7']

const form = reactive({ name: '', color: null as string | null })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.column] as const,
  ([open]) => {
    if (!open) return

    form.name = props.column?.name ?? ''
    form.color = props.column?.color ?? null
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
    const input = { name: form.name, color: form.color }

    const saved = isEditing.value
      ? await projectColumnsService.update(props.column!.id, input)
      : await projectColumnsService.create(props.projectId, input)

    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.projects.columnSaveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    size="sm"
    :title="isEditing ? t('admin.projects.editColumnTitle') : t('admin.projects.addColumnTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.projects.columnName')" :error="errors.name?.[0]" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.projects.columnColor') }}</label>
        <div class="flex gap-2">
          <button
            v-for="swatch in palette"
            :key="swatch"
            type="button"
            class="h-7 w-7 rounded-full border-2 transition-transform"
            :class="form.color === swatch ? 'scale-110 border-neutral-900' : 'border-transparent'"
            :style="{ backgroundColor: swatch }"
            :aria-label="swatch"
            @click="form.color = form.color === swatch ? null : swatch"
          />
        </div>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

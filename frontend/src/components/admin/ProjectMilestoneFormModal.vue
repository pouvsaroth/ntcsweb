<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { projectMilestonesService, type ProjectMilestone } from '@/services/projects'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  projectId: number
  /** Present when editing; absent when adding. */
  milestone?: ProjectMilestone | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [milestone: ProjectMilestone] }>()

const { t } = useI18n()

const isEditing = computed(() => props.milestone != null)

const form = reactive({ name: '', start_date: '', due_date: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.milestone] as const,
  ([open]) => {
    if (!open) return

    form.name = props.milestone?.name ?? ''
    form.start_date = props.milestone?.start_date ?? ''
    form.due_date = props.milestone?.due_date ?? ''
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
    const input = { name: form.name, start_date: form.start_date || null, due_date: form.due_date || null }

    const saved = isEditing.value
      ? await projectMilestonesService.update(props.milestone!.id, input)
      : await projectMilestonesService.create(props.projectId, input)

    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.projects.actionFailed')
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
    :title="isEditing ? t('admin.projects.editMilestoneTitle') : t('admin.projects.addMilestoneTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.projects.milestoneName')" :error="errors.name?.[0]" />

      <div class="grid grid-cols-2 gap-3">
        <BaseInput v-model="form.start_date" type="date" :label="t('admin.projects.taskStartDate')" :error="errors.start_date?.[0]" />
        <BaseInput v-model="form.due_date" type="date" :label="t('admin.projects.taskDueDate')" :error="errors.due_date?.[0]" />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

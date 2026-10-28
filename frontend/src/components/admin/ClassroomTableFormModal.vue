<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { type Classroom } from '@/services/classrooms'
import { type ClassroomTable, classroomTablesService } from '@/services/classroomTables'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  classroom: Classroom
  table?: ClassroomTable | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.table != null)

const form = reactive({ name: '', sort_order: 0, allow_many_students: false, remark: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.table] as const,
  ([open]) => {
    if (!open) return

    form.name = props.table?.name ?? ''
    form.sort_order = props.table?.sort_order ?? 0
    form.allow_many_students = props.table?.allow_many_students ?? false
    form.remark = props.table?.remark ?? ''
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
    const input = { ...form, remark: form.remark.trim() || null, classroom_id: props.classroom.id }

    if (isEditing.value) {
      await classroomTablesService.update(props.table!.id, input)
    } else {
      await classroomTablesService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.classroomTables.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.classroomTables.editTitle') : t('admin.classroomTables.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.classroomTables.name')" :error="errors.name?.[0]" />
      <BaseInput
        :model-value="String(form.sort_order)"
        type="number"
        :label="t('admin.classroomTables.sortOrder')"
        :error="errors.sort_order?.[0]"
        @update:model-value="form.sort_order = Number($event) || 0"
      />
      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.allow_many_students" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.classroomTables.allowMany') }}
      </label>
      <BaseInput v-model="form.remark" :label="t('admin.classroomTables.remark')" :error="errors.remark?.[0]" />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

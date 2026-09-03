<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { academicProgramsService, type AcademicProgram } from '@/services/academicPrograms'
import { type BookCategory, bookCategoriesService } from '@/services/bookCategories'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  bookCategory?: BookCategory | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.bookCategory != null)

const programs = ref<AcademicProgram[]>([])
const programOptions = computed(() => programs.value.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` })))

const form = reactive({
  name: '',
  academic_program_id: null as number | null,
  is_active: true,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

onMounted(async () => {
  programs.value = await academicProgramsService.listAll()
})

watch(
  () => [props.modelValue, props.bookCategory] as const,
  ([open]) => {
    if (!open) return

    form.name = props.bookCategory?.name ?? ''
    form.academic_program_id = props.bookCategory?.academic_program_id ?? null
    form.is_active = props.bookCategory?.is_active ?? true
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  if (form.academic_program_id === null) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input = { ...form, academic_program_id: form.academic_program_id }

    if (isEditing.value) {
      await bookCategoriesService.update(props.bookCategory!.id, input)
    } else {
      await bookCategoriesService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.bookCategories.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.bookCategories.editTitle') : t('admin.bookCategories.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseSelect
        :model-value="form.academic_program_id !== null ? String(form.academic_program_id) : ''"
        :options="programOptions"
        required
        :placeholder="t('admin.bookCategories.selectProgram')"
        :label="t('admin.bookCategories.program')"
        :error="errors.academic_program_id?.[0]"
        @update:model-value="form.academic_program_id = $event ? Number($event) : null"
      />

      <BaseInput v-model="form.name" required :label="t('admin.bookCategories.name')" :error="errors.name?.[0]" />

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.bookCategories.statusActive') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="form.academic_program_id === null" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

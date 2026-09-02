<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { academicProgramsService, type AcademicProgram } from '@/services/academicPrograms'
import { booksService, type Book } from '@/services/books'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when creating a new book. */
  book?: Book | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.book != null)

const form = reactive({
  title: '',
  author: '',
  isbn: '',
  publisher: '',
  description: '',
  quantity: 1,
  fee: null as number | null,
  status: 'active' as 'active' | 'inactive',
  program_ids: [] as number[],
})

const programs = ref<AcademicProgram[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.books.statusActive') },
  { value: 'inactive', label: t('admin.books.statusInactive') },
])

function toggleProgram(programId: number, checked: boolean) {
  if (checked) {
    if (!form.program_ids.includes(programId)) form.program_ids.push(programId)
  } else {
    form.program_ids = form.program_ids.filter((id) => id !== programId)
  }
}

onMounted(async () => {
  programs.value = await academicProgramsService.listAll()
})

watch(
  () => [props.modelValue, props.book] as const,
  ([open]) => {
    if (!open) return

    form.title = props.book?.title ?? ''
    form.author = props.book?.author ?? ''
    form.isbn = props.book?.isbn ?? ''
    form.publisher = props.book?.publisher ?? ''
    form.description = props.book?.description ?? ''
    form.quantity = props.book?.quantity ?? 1
    form.fee = props.book?.fee ?? null
    form.status = props.book?.status ?? 'active'
    form.program_ids = props.book?.programs?.map((p) => p.id) ?? []
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
      await booksService.update(props.book!.id, form)
    } else {
      await booksService.create(form)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.books.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.books.editTitle') : t('admin.books.createTitle')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.title" required :label="t('admin.books.bookTitle')" :error="errors.title?.[0]" />

      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.author" :label="t('admin.books.author')" :error="errors.author?.[0]" />
        <BaseInput v-model="form.isbn" :label="t('admin.books.isbn')" :error="errors.isbn?.[0]" />
        <BaseInput v-model="form.publisher" :label="t('admin.books.publisher')" :error="errors.publisher?.[0]" />
        <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.books.status')" />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.books.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <BaseInput
          :model-value="String(form.quantity)"
          type="number"
          :label="t('admin.books.quantity')"
          :error="errors.quantity?.[0]"
          @update:model-value="form.quantity = Number($event) || 0"
        />
        <BaseInput
          :model-value="form.fee !== null ? String(form.fee) : ''"
          type="number"
          :label="t('admin.books.fee')"
          :hint="t('admin.books.feeHint')"
          :error="errors.fee?.[0]"
          @update:model-value="form.fee = $event ? Number($event) : null"
        />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.books.programs') }}</label>
        <p class="mb-2 text-xs text-neutral-500">{{ t('admin.books.programsHint') }}</p>
        <p v-if="programs.length === 0" class="text-sm text-neutral-500">{{ t('admin.books.noProgramsAvailable') }}</p>
        <div v-else class="grid gap-2 sm:grid-cols-2">
          <label v-for="program in programs" :key="program.id" class="flex items-center gap-2 rounded-lg border border-neutral-200 p-2.5 text-sm">
            <input
              type="checkbox"
              :checked="form.program_ids.includes(program.id)"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              @change="toggleProgram(program.id, ($event.target as HTMLInputElement).checked)"
            />
            <span class="flex-1 text-neutral-700">{{ program.code }} — {{ program.name }}</span>
          </label>
        </div>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { academicProgramsService, type AcademicProgram } from '@/services/academicPrograms'
import { bookCategoriesService, type BookCategory } from '@/services/bookCategories'
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
  status: 'active' as 'active' | 'inactive',
  academic_program_id: null as number | null,
  book_category_id: null as number | null,
})

const programs = ref<AcademicProgram[]>([])
const categories = ref<BookCategory[]>([])
const loadingCategories = ref(false)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.books.statusActive') },
  { value: 'inactive', label: t('admin.books.statusInactive') },
])

const programOptions = computed(() => programs.value.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` })))
const categoryOptions = computed(() => categories.value.map((c) => ({ value: String(c.id), label: c.name })))

onMounted(async () => {
  programs.value = await academicProgramsService.listAll()
})

// Picking a different program invalidates whatever category was chosen for
// the previous one — a category only ever belongs to one program. Fetching
// is async, so by the time it resolves the fields below are already both
// set (whether from a user's own program change or from opening the modal
// to edit a book) — the book's own category simply survives the check
// below when it's genuinely still valid for the resolved program.
watch(
  () => form.academic_program_id,
  async (academicProgramId) => {
    if (academicProgramId === null) {
      categories.value = []
      return
    }

    loadingCategories.value = true
    try {
      categories.value = await bookCategoriesService.listAllForProgram(academicProgramId)
    } finally {
      loadingCategories.value = false
    }

    if (!categories.value.some((c) => c.id === form.book_category_id)) form.book_category_id = null
  },
  { immediate: true },
)

watch(
  () => [props.modelValue, props.book] as const,
  ([open]) => {
    if (!open) return

    form.title = props.book?.title ?? ''
    form.author = props.book?.author ?? ''
    form.isbn = props.book?.isbn ?? ''
    form.publisher = props.book?.publisher ?? ''
    form.description = props.book?.description ?? ''
    form.status = props.book?.status ?? 'active'
    form.academic_program_id = props.book?.academic_program_id ?? null
    form.book_category_id = props.book?.book_category_id ?? null
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
      await booksService.update(props.book!.id, input)
    } else {
      await booksService.create(input)
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

      <BaseSelect
        :model-value="form.academic_program_id !== null ? String(form.academic_program_id) : ''"
        :options="programOptions"
        required
        :placeholder="t('admin.books.selectProgram')"
        :label="t('admin.books.program')"
        :error="errors.academic_program_id?.[0]"
        @update:model-value="form.academic_program_id = $event ? Number($event) : null"
      />

      <div>
        <BaseSelect
          :model-value="form.book_category_id !== null ? String(form.book_category_id) : ''"
          :options="categoryOptions"
          :disabled="form.academic_program_id === null || loadingCategories"
          :placeholder="loadingCategories ? t('common.loading') : t('admin.books.selectCategory')"
          :label="t('admin.books.category')"
          :error="errors.book_category_id?.[0]"
          @update:model-value="form.book_category_id = $event ? Number($event) : null"
        />
        <p v-if="form.academic_program_id === null" class="mt-1.5 text-xs text-neutral-500">{{ t('admin.books.pickProgramFirst') }}</p>
        <p v-else-if="!loadingCategories && categories.length === 0" class="mt-1.5 text-xs text-neutral-500">
          {{ t('admin.books.noCategoriesAvailable') }}
        </p>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="form.academic_program_id === null" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

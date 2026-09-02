<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { academicProgramsService, type AcademicProgram } from '@/services/academicPrograms'
import { type Book, booksService } from '@/services/books'
import { coursePackagesService, type CoursePackage } from '@/services/coursePackages'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  coursePackage?: CoursePackage | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.coursePackage != null)

const form = reactive({
  code: '',
  name: '',
  academic_program_id: null as number | null,
  description: '',
  price: null as number | null,
  duration: '',
  is_active: true,
  book_ids: [] as number[],
})

const programs = ref<AcademicProgram[]>([])
const books = ref<Book[]>([])
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const programOptions = computed(() => programs.value.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` })))

// Only books tagged to the chosen academic program make sense in this
// package's menu — mirrors the server's own "package must belong to the
// class's program" rule (EnrollmentService::assertEnrollable()).
const availableBooks = computed(() =>
  form.academic_program_id === null ? [] : books.value.filter((b) => b.programs?.some((p) => p.id === form.academic_program_id)),
)

function toggleBook(bookId: number, checked: boolean) {
  if (checked) {
    if (!form.book_ids.includes(bookId)) form.book_ids.push(bookId)
  } else {
    form.book_ids = form.book_ids.filter((id) => id !== bookId)
  }
}

onMounted(async () => {
  ;[programs.value, books.value] = await Promise.all([academicProgramsService.listAll(), booksService.listAll()])
})

// Changing the program invalidates whatever books were picked for the
// previous one — the menu only makes sense within one program. Skipped
// while the form is first populated for editing (the watch below sets both
// academic_program_id and book_ids together).
let hydrating = false

watch(
  () => form.academic_program_id,
  () => {
    if (hydrating) return
    form.book_ids = form.book_ids.filter((id) => availableBooks.value.some((b) => b.id === id))
  },
)

watch(
  () => [props.modelValue, props.coursePackage] as const,
  ([open]) => {
    if (!open) return

    hydrating = true
    form.code = props.coursePackage?.code ?? ''
    form.name = props.coursePackage?.name ?? ''
    form.academic_program_id = props.coursePackage?.academic_program_id ?? null
    form.description = props.coursePackage?.description ?? ''
    form.price = props.coursePackage?.price ?? null
    form.duration = props.coursePackage?.duration ?? ''
    form.is_active = props.coursePackage?.is_active ?? true
    form.book_ids = props.coursePackage?.books?.map((b) => b.id) ?? []
    errors.value = {}
    generalError.value = null
    hydrating = false
  },
  { immediate: true },
)

async function submit() {
  if (form.academic_program_id === null || form.price === null) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input = { ...form, academic_program_id: form.academic_program_id, price: form.price }

    if (isEditing.value) {
      await coursePackagesService.update(props.coursePackage!.id, input)
    } else {
      await coursePackagesService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.coursePackages.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.coursePackages.editTitle') : t('admin.coursePackages.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseAlert v-if="isEditing" variant="info">{{ t('admin.coursePackages.priceChangeWarning') }}</BaseAlert>

      <BaseInput v-model="form.code" required :label="t('admin.coursePackages.code')" :error="errors.code?.[0]" />
      <BaseInput v-model="form.name" required :label="t('admin.coursePackages.name')" :error="errors.name?.[0]" />

      <BaseSelect
        :model-value="form.academic_program_id !== null ? String(form.academic_program_id) : ''"
        :options="programOptions"
        required
        :placeholder="t('admin.coursePackages.selectProgram')"
        :label="t('admin.coursePackages.program')"
        :error="errors.academic_program_id?.[0]"
        @update:model-value="form.academic_program_id = $event ? Number($event) : null"
      />

      <div class="grid grid-cols-2 gap-4">
        <BaseInput
          :model-value="form.price !== null ? String(form.price) : ''"
          type="number"
          step="0.01"
          required
          :label="t('admin.coursePackages.price')"
          :hint="t('admin.coursePackages.priceHint')"
          :error="errors.price?.[0]"
          @update:model-value="form.price = $event ? Number($event) : null"
        />
        <BaseInput v-model="form.duration" :label="t('admin.coursePackages.duration')" :error="errors.duration?.[0]" />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.coursePackages.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('admin.coursePackages.books') }} <span class="text-danger-600">*</span>
        </label>
        <p class="mb-2 text-xs text-neutral-500">{{ t('admin.coursePackages.booksHint') }}</p>
        <p v-if="form.academic_program_id === null" class="text-sm text-neutral-500">{{ t('admin.coursePackages.pickProgramFirst') }}</p>
        <p v-else-if="availableBooks.length === 0" class="text-sm text-neutral-500">{{ t('admin.coursePackages.noBooksAvailable') }}</p>
        <div v-else class="grid gap-2 sm:grid-cols-2">
          <label v-for="book in availableBooks" :key="book.id" class="flex items-center gap-2 rounded-lg border border-neutral-200 p-2.5 text-sm">
            <input
              type="checkbox"
              :checked="form.book_ids.includes(book.id)"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              @change="toggleBook(book.id, ($event.target as HTMLInputElement).checked)"
            />
            <span class="flex-1 text-neutral-700">{{ book.title }}</span>
          </label>
        </div>
        <p v-if="errors.book_ids?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.book_ids[0] }}</p>
      </div>

      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.coursePackages.statusActive') }}
      </label>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="form.academic_program_id === null || form.price === null || form.book_ids.length === 0" @click="submit">
        {{ t('common.save') }}
      </BaseButton>
    </template>
  </BaseModal>
</template>

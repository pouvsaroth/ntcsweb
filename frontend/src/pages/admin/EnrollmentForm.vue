<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { classesService, type SchoolClass } from '@/services/classes'
import { enrollmentsService } from '@/services/enrollments'
import { studentsService, type Student } from '@/services/students'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const router = useRouter()

/** yyyy-MM-dd in the viewer's local time. */
function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const studentSearch = ref('')
const studentResults = ref<Student[]>([])
const selectedStudent = ref<Student | null>(null)
const searchingStudents = ref(false)
let studentSearchDebounce: ReturnType<typeof setTimeout> | undefined

const classes = ref<SchoolClass[]>([])
const loadingClasses = ref(true)

const form = reactive({
  class_id: null as number | null,
  book_id: null as number | null,
  enrolled_at: today(),
  fee: null as number | null,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const selectedClass = computed(() => classes.value.find((c) => c.id === form.class_id) ?? null)

const classOptions = computed(() => classes.value.map((c) => ({ value: String(c.id), label: c.name })))

const bookOptions = computed(() =>
  (selectedClass.value?.books ?? []).map((book) => ({
    value: String(book.id),
    label: book.fee !== null ? `${book.title} — ${book.fee.toFixed(2)}` : book.title,
  })),
)

// Picking a different class invalidates whatever book was chosen for the
// previous one — the menu (class_book) is different per class.
watch(
  () => form.class_id,
  () => {
    form.book_id = null
    form.fee = null
  },
)

function onBookChange(value: string) {
  form.book_id = value ? Number(value) : null
  const book = selectedClass.value?.books.find((b) => b.id === form.book_id)
  // Pre-fills from the book's catalog fee — the admin can still adjust it
  // (a discount) before saving; this never writes back to the book itself.
  form.fee = book?.fee ?? form.fee
}

function onStudentSearchInput() {
  clearTimeout(studentSearchDebounce)
  if (!studentSearch.value.trim()) {
    studentResults.value = []
    return
  }
  studentSearchDebounce = setTimeout(async () => {
    searchingStudents.value = true
    try {
      const result = await studentsService.list({ search: studentSearch.value })
      studentResults.value = result.data
    } finally {
      searchingStudents.value = false
    }
  }, 350)
}

function selectStudent(student: Student) {
  selectedStudent.value = student
  studentResults.value = []
  studentSearch.value = ''
}

async function submit() {
  if (!selectedStudent.value || !form.class_id || !form.book_id || form.fee === null) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await enrollmentsService.create({
      student_id: selectedStudent.value.id,
      class_id: form.class_id,
      book_id: form.book_id,
      enrolled_at: form.enrolled_at,
      fee: form.fee,
      status: 'active',
    })

    await router.push('/admin/enrollments')
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.enrollments.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  loadingClasses.value = true
  try {
    classes.value = await classesService.listAll()
  } finally {
    loadingClasses.value = false
  }
})
</script>

<template>
  <div class="max-w-2xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.enrollments.createTitle') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.enrollments.createSubtitle') }}</p>
    </div>

    <form class="space-y-6" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">
          {{ t('admin.enrollments.student') }} <span class="text-danger-600">*</span>
        </label>

        <div v-if="selectedStudent" class="flex items-center justify-between rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm">
          <span>{{ selectedStudent.full_name }} <span class="text-neutral-400">({{ selectedStudent.student_code }})</span></span>
          <button type="button" class="font-medium text-secondary-600 hover:text-secondary-700" @click="selectedStudent = null">
            {{ t('common.change') }}
          </button>
        </div>
        <template v-else>
          <input
            v-model="studentSearch"
            type="search"
            :placeholder="t('admin.enrollments.searchStudentPlaceholder')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
            @input="onStudentSearchInput"
          />
          <ul v-if="studentResults.length > 0" class="mt-1 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
            <li v-for="student in studentResults" :key="student.id">
              <button
                type="button"
                class="block w-full px-3 py-2 text-left text-sm hover:bg-neutral-50"
                @click="selectStudent(student)"
              >
                {{ student.full_name }} <span class="text-neutral-400">({{ student.student_code }})</span>
              </button>
            </li>
          </ul>
        </template>
        <p v-if="errors.student_id?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.student_id[0] }}</p>
      </div>

      <BaseSelect
        :model-value="form.class_id !== null ? String(form.class_id) : ''"
        :options="classOptions"
        :disabled="loadingClasses"
        :placeholder="t('admin.enrollments.selectClass')"
        :label="t('admin.enrollments.class')"
        :hint="t('admin.enrollments.classHint')"
        required
        :error="errors.class_id?.[0]"
        @update:model-value="form.class_id = $event ? Number($event) : null"
      />

      <BaseSelect
        :model-value="form.book_id !== null ? String(form.book_id) : ''"
        :options="bookOptions"
        :disabled="!form.class_id"
        :placeholder="t('admin.enrollments.selectBook')"
        :label="t('admin.enrollments.book')"
        required
        :error="errors.book_id?.[0]"
        @update:model-value="onBookChange"
      />

      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.enrolled_at" type="date" required :label="t('admin.enrollments.enrolledAt')" :error="errors.enrolled_at?.[0]" />
        <BaseInput
          :model-value="form.fee !== null ? String(form.fee) : ''"
          type="number"
          required
          :label="t('admin.enrollments.fee')"
          :hint="t('admin.enrollments.feeHint')"
          :error="errors.fee?.[0]"
          @update:model-value="form.fee = $event ? Number($event) : null"
        />
      </div>

      <div class="flex gap-3">
        <BaseButton type="submit" :loading="submitting" :disabled="!selectedStudent || !form.class_id || !form.book_id">
          {{ t('common.save') }}
        </BaseButton>
        <BaseButton type="button" variant="outline" @click="router.push('/admin/enrollments')">{{ t('common.cancel') }}</BaseButton>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { academicProgramsService, type AcademicProgram } from '@/services/academicPrograms'
import { classesService, type SchoolClass } from '@/services/classes'
import { coursePackagesService, type CoursePackage } from '@/services/coursePackages'
import { enrollmentsService, type Enrollment } from '@/services/enrollments'
import { studentsService, type Student } from '@/services/students'
import { ApiRequestError } from '@/types/api'

/**
 * Enrollments > Add students to class: many students into one company class
 * (Billing > Companies) and course at once. No fee or payment here — the
 * company pays one total for the class. See EnrollmentService::enrollClassBatch().
 */
const { t } = useI18n()
const router = useRouter()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const programs = ref<AcademicProgram[]>([])
const classes = ref<SchoolClass[]>([])
const packages = ref<CoursePackage[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const form = reactive({
  academic_program_id: null as number | null,
  course_package_id: null as number | null,
  class_id: null as number | null,
  enrolled_at: today(),
})

interface Row {
  student: Student
  table_id: number | null
}
const rows = ref<Row[]>([])

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const programOptions = computed(() => programs.value.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` })))
const packageOptions = computed(() =>
  packages.value.filter((pkg) => pkg.academic_program_id === form.academic_program_id).map((pkg) => ({ value: String(pkg.id), label: pkg.name })),
)
// Only company classes: a class paid by students themselves gives each one an invoice (Enrollment form).
const classOptions = computed(() =>
  classes.value
    .filter((c) => c.academic_program_id === form.academic_program_id && c.company_id !== null)
    .map((c) => ({ value: String(c.id), label: `${c.name} — ${c.company?.name ?? ''}` })),
)
const selectedClass = computed(() => classes.value.find((c) => c.id === form.class_id) ?? null)
const noCompanyClasses = computed(() => form.academic_program_id !== null && classOptions.value.length === 0)
// Where the company classes are, when the picked program has none.
const companyClassPrograms = computed(() =>
  [...new Set(classes.value.map((c) => c.academic_program_id))]
    .map((id) => programs.value.find((p) => p.id === id)?.name)
    .filter(Boolean)
    .join(', '),
)

// Students already in the class, so staff see who is in it before adding more.
const current = ref<Enrollment[]>([])
const loadingCurrent = ref(false)

const tables = ref<{ total_tables: number; available: { id: number; name: string }[] } | null>(null)
const tableRequired = computed(() => (tables.value?.total_tables ?? 0) > 0)
const tableOptions = computed(() => (tables.value?.available ?? []).map((table) => ({ value: String(table.id), label: table.name })))

watch(
  () => form.academic_program_id,
  () => {
    form.course_package_id = null
    onClassChange('')
  },
)

async function onClassChange(value: string) {
  form.class_id = value ? Number(value) : null
  tables.value = null
  current.value = []
  rows.value.forEach((row) => (row.table_id = null))
  if (form.class_id === null) return

  loadingCurrent.value = true
  try {
    const [loadedTables, enrollments] = await Promise.all([
      classesService.availableTables(form.class_id),
      enrollmentsService.listAll({ class_id: form.class_id, status: 'active' }),
    ])
    tables.value = loadedTables
    current.value = enrollments
    rows.value.forEach((row) => (row.table_id = nextFreeTable()))
  } finally {
    loadingCurrent.value = false
  }
}

/** The first offered table no row has picked yet — a starting point staff can change. */
function nextFreeTable(): number | null {
  if (!tableRequired.value) return null
  const picked = new Set(rows.value.map((r) => r.table_id))
  return tables.value?.available.find((table) => !picked.has(table.id))?.id ?? null
}

// --- Student search ------------------------------------------------------

const studentSearch = ref('')
const studentResults = ref<Student[]>([])
const searchingStudents = ref(false)
let studentSearchDebounce: ReturnType<typeof setTimeout> | undefined

const inClassIds = computed(() => new Set(current.value.map((e) => e.student?.id)))
const visibleResults = computed(() => studentResults.value.filter((s) => !rows.value.some((r) => r.student.id === s.id)))

function onStudentSearchInput() {
  clearTimeout(studentSearchDebounce)
  if (!studentSearch.value.trim()) {
    studentResults.value = []
    return
  }
  studentSearchDebounce = setTimeout(async () => {
    searchingStudents.value = true
    try {
      studentResults.value = (await studentsService.list({ search: studentSearch.value })).data
    } finally {
      searchingStudents.value = false
    }
  }, 350)
}

function addStudent(student: Student) {
  rows.value.push({ student, table_id: null })
  rows.value[rows.value.length - 1].table_id = nextFreeTable()
  errors.value = {}
}

function addAllResults() {
  visibleResults.value.filter((s) => !inClassIds.value.has(s.id)).forEach(addStudent)
  studentResults.value = []
  studentSearch.value = ''
}

function removeRow(index: number) {
  rows.value.splice(index, 1)
  errors.value = {}
}

function rowError(index: number): string | undefined {
  return errors.value[`students.${index}.student_id`]?.[0] ?? errors.value[`students.${index}.table_id`]?.[0]
}

const canSubmit = computed(
  () => form.class_id !== null && form.course_package_id !== null && rows.value.length > 0 && (!tableRequired.value || rows.value.every((r) => r.table_id !== null)),
)

async function submit() {
  if (!canSubmit.value) return
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await enrollmentsService.enrollClassBatch({
      class_id: form.class_id!,
      course_package_id: form.course_package_id!,
      enrolled_at: form.enrolled_at,
      students: rows.value.map((r) => ({ student_id: r.student.id, table_id: r.table_id })),
    })
    await router.push(`/admin/classes/${form.class_id}/students`)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
      const rowErrors = Object.keys(error.errors).some((key) => key.startsWith('students.'))
      generalError.value = rowErrors ? t('admin.enrollments.batchRowErrors') : (Object.values(error.errors)[0]?.[0] ?? null)
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.enrollments.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  try {
    const [loadedPrograms, loadedClasses, loadedPackages] = await Promise.all([
      academicProgramsService.listAll().catch(() => []),
      classesService.listCompanyClasses().catch(() => []),
      coursePackagesService.listAll().catch(() => []),
    ])
    programs.value = loadedPrograms
    // Upcoming classes too: a company class is often set up before it starts.
    classes.value = loadedClasses.filter((c) => c.status === 'upcoming' || c.status === 'active')
    packages.value = loadedPackages.filter((pkg) => pkg.is_active)
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.enrollments.saveFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.enrollments.batchTitle') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.enrollments.batchSubtitle') }}</p>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <form v-else class="grid gap-8 lg:grid-cols-2" @submit.prevent="submit">
      <div class="space-y-6">
        <BaseSelect
          :model-value="form.academic_program_id !== null ? String(form.academic_program_id) : ''"
          :options="programOptions"
          :placeholder="t('admin.enrollments.selectProgram')"
          :label="t('admin.enrollments.program')"
          required
          @update:model-value="form.academic_program_id = $event ? Number($event) : null"
        />
        <BaseSelect
          :model-value="form.course_package_id !== null ? String(form.course_package_id) : ''"
          :options="packageOptions"
          :disabled="!form.academic_program_id"
          :placeholder="t('admin.enrollments.selectPackage')"
          :label="t('admin.enrollments.package')"
          required
          :error="errors.course_package_id?.[0]"
          @update:model-value="form.course_package_id = $event ? Number($event) : null"
        />
        <div>
          <BaseSelect
            :model-value="form.class_id !== null ? String(form.class_id) : ''"
            :options="classOptions"
            :disabled="!form.academic_program_id"
            :placeholder="t('admin.enrollments.selectClass')"
            :label="t('admin.enrollments.class')"
            :hint="t('admin.enrollments.batchClassHint')"
            required
            :error="errors.class_id?.[0]"
            @update:model-value="onClassChange"
          />
          <p v-if="noCompanyClasses" class="mt-1.5 text-sm text-warning-700">{{ t('admin.enrollments.batchNoCompanyClasses') }}<template v-if="companyClassPrograms"> {{ t('admin.enrollments.batchCompanyClassesIn', { programs: companyClassPrograms }) }}</template></p>
        </div>
        <BaseInput v-model="form.enrolled_at" type="date" required :label="t('admin.enrollments.enrolledAt')" :error="errors.enrolled_at?.[0]" />

        <div v-if="selectedClass" class="rounded-[--radius-card] border border-neutral-200 bg-neutral-50 p-3 text-sm">
          <p class="font-medium text-neutral-800">{{ t('admin.enrollments.batchPaidBy', { company: selectedClass.company?.name ?? '' }) }}</p>
          <p class="mt-0.5 text-neutral-500">{{ t('admin.enrollments.batchNoInvoice') }}</p>
          <div class="mt-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('admin.enrollments.batchInClass', { count: current.length }) }}</p>
            <BaseSpinner v-if="loadingCurrent" class="mt-2" />
            <ul v-else-if="current.length" class="mt-1 max-h-48 space-y-0.5 overflow-y-auto text-neutral-700">
              <li v-for="e in current" :key="e.id">{{ e.student?.full_name }} <span class="text-neutral-400">({{ e.student?.student_code }})</span></li>
            </ul>
            <p v-else class="mt-1 text-neutral-500">{{ t('admin.enrollments.batchClassEmpty') }}</p>
          </div>
        </div>
      </div>

      <div class="space-y-4">
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.enrollments.batchAddStudents') }}</label>
          <input
            v-model="studentSearch"
            type="search"
            :placeholder="t('admin.enrollments.searchStudentPlaceholder')"
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
            @input="onStudentSearchInput"
          />
          <div v-if="visibleResults.length" class="mt-1 rounded-lg border border-neutral-200">
            <ul class="max-h-64 divide-y divide-neutral-100 overflow-y-auto">
              <li v-for="student in visibleResults" :key="student.id">
                <button
                  type="button"
                  class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-50"
                  :disabled="inClassIds.has(student.id)"
                  @click="addStudent(student)"
                >
                  <span>{{ student.full_name }} <span class="text-neutral-400">({{ student.student_code }})</span></span>
                  <span class="text-xs text-neutral-500">{{ inClassIds.has(student.id) ? t('admin.enrollments.batchAlreadyIn') : '+' }}</span>
                </button>
              </li>
            </ul>
            <button type="button" class="w-full border-t border-neutral-100 px-3 py-2 text-left text-sm font-medium text-secondary-600 hover:bg-neutral-50" @click="addAllResults">
              {{ t('admin.enrollments.batchAddAll') }}
            </button>
          </div>
          <p v-else-if="searchingStudents" class="mt-1 text-sm text-neutral-500">{{ t('common.loading') }}</p>
        </div>

        <div>
          <p class="mb-2 text-sm font-semibold text-neutral-800">{{ t('admin.enrollments.batchToAdd', { count: rows.length }) }}</p>
          <p v-if="rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">
            {{ t('admin.enrollments.batchEmpty') }}
          </p>
          <ul v-else class="space-y-2">
            <li
              v-for="(row, index) in rows"
              :key="row.student.id"
              class="rounded-[--radius-card] border bg-white p-3 shadow-[--shadow-card]"
              :class="rowError(index) ? 'border-danger-300' : 'border-neutral-200'"
            >
              <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-medium text-neutral-800">{{ index + 1 }}. {{ row.student.full_name }}</p>
                  <p class="text-xs text-neutral-500">{{ row.student.student_code }}</p>
                </div>
                <BaseSelect
                  v-if="tableRequired"
                  class="sm:w-40"
                  :model-value="row.table_id !== null ? String(row.table_id) : ''"
                  :options="tableOptions"
                  :placeholder="t('admin.enrollments.selectTable')"
                  @update:model-value="row.table_id = $event ? Number($event) : null"
                />
                <button type="button" class="self-end text-sm font-medium text-danger-600 hover:text-red-700 sm:self-center" @click="removeRow(index)">
                  {{ t('common.remove') }}
                </button>
              </div>
              <p v-if="rowError(index)" class="mt-1.5 text-sm text-danger-600">{{ rowError(index) }}</p>
            </li>
          </ul>
          <p v-if="tableRequired && tableOptions.length === 0" class="mt-2 text-sm text-danger-600">{{ t('admin.enrollments.noTablesAvailable') }}</p>
        </div>

        <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

        <div class="flex justify-end gap-3">
          <BaseButton variant="outline" to="/admin/enrollments">{{ t('common.cancel') }}</BaseButton>
          <BaseButton type="submit" :loading="submitting" :disabled="!canSubmit">
            {{ rows.length ? t('admin.enrollments.batchSave', { count: rows.length }) : t('admin.enrollments.batchTitle') }}
          </BaseButton>
        </div>
      </div>
    </form>
  </div>
</template>

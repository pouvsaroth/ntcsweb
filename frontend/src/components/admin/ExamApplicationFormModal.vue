<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AddressSelects from '@/components/admin/AddressSelects.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import WebcamCaptureModal from '@/components/ui/WebcamCaptureModal.vue'
import { classroomTablesService } from '@/services/classroomTables'
import { classroomsService, type Classroom } from '@/services/classrooms'
import { coursePackagesService } from '@/services/coursePackages'
import {
  examApplicationsService,
  type ExamApplicationInput,
  type ExamApplicationLookup,
} from '@/services/examApplications'
import { studentsService } from '@/services/students'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{ modelValue: boolean; initialEnrollmentCode?: string | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()
const auth = useAuthStore()

const canEditStudent = computed(() => auth.can('students.update'))

const enrollmentCode = ref('')
const lookingUp = ref(false)
const lookupError = ref<string | null>(null)
const lookup = ref<ExamApplicationLookup | null>(null)

// --- Student Information (editable, saved back to the real Student record) ---

const studentForm = ref({
  first_name: '',
  last_name: '',
  english_name: '',
  gender: '',
  date_of_birth: '',
  phone: '',
  village_code: '',
})
const photoFile = ref<File | null>(null)
const photoPreview = ref<string | null>(null)
const webcamModalOpen = ref(false)

function setPhotoFile(file: File) {
  photoFile.value = file
  photoPreview.value = URL.createObjectURL(file)
}

function onPhotoChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (file) setPhotoFile(file)
}

// --- Examination Information ---

const examForm = ref({
  book_id: null as number | null,
  classroom_id: null as number | null,
  table_id: null as number | null,
  exam_date: '',
  exam_time: '',
  exam_time_out: '',
  remark: '',
})

const books = ref<{ id: number; title: string }[]>([])
const classrooms = ref<Classroom[]>([])
const tables = ref<{ id: number; name: string }[]>([])
const loadingTables = ref(false)

const bookOptions = computed(() => books.value.map((b) => ({ value: String(b.id), label: b.title })))
const classroomOptions = computed(() => classrooms.value.map((c) => ({ value: String(c.id), label: c.name })))
const tableOptions = computed(() => tables.value.map((tbl) => ({ value: String(tbl.id), label: tbl.name })))

async function onClassroomChange(value: string) {
  examForm.value.classroom_id = value ? Number(value) : null
  examForm.value.table_id = null
  tables.value = []

  if (!examForm.value.classroom_id) return
  loadingTables.value = true
  try {
    tables.value = await classroomTablesService.listByClassroom(examForm.value.classroom_id)
  } finally {
    loadingTables.value = false
  }
}

// --- Submit state ---

const submitting = ref(false)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)

function resetForms() {
  studentForm.value = { first_name: '', last_name: '', english_name: '', gender: '', date_of_birth: '', phone: '', village_code: '' }
  examForm.value = { book_id: null, classroom_id: null, table_id: null, exam_date: '', exam_time: '', exam_time_out: '', remark: '' }
  photoFile.value = null
  photoPreview.value = null
  books.value = []
  tables.value = []
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    enrollmentCode.value = props.initialEnrollmentCode ?? ''
    lookup.value = null
    lookupError.value = null
    errors.value = {}
    generalError.value = null
    resetForms()
    void loadClassrooms()
    if (enrollmentCode.value) void showData()
  },
)

async function loadClassrooms() {
  classrooms.value = await classroomsService.listAll()
}

async function showData() {
  if (!enrollmentCode.value.trim()) return
  lookingUp.value = true
  lookupError.value = null
  lookup.value = null

  try {
    lookup.value = await examApplicationsService.lookup(enrollmentCode.value.trim())

    const student = lookup.value.student
    studentForm.value = {
      first_name: student.first_name ?? '',
      last_name: student.last_name ?? '',
      english_name: student.english_name ?? '',
      gender: student.gender ?? '',
      date_of_birth: student.date_of_birth ?? '',
      phone: student.phone ?? '',
      village_code: student.village_code ?? '',
    }
    photoPreview.value = student.photo_url

    if (lookup.value.course_package) {
      const pkg = await coursePackagesService.get(lookup.value.course_package.id)
      books.value = pkg.books ?? []
    }

    const existing = lookup.value.exam_application
    if (existing) {
      examForm.value = {
        book_id: existing.book?.id ?? null,
        classroom_id: existing.classroom?.id ?? null,
        table_id: existing.table?.id ?? null,
        exam_date: existing.exam_date ?? '',
        exam_time: existing.exam_time?.slice(0, 5) ?? '',
        exam_time_out: existing.exam_time_out?.slice(0, 5) ?? '',
        remark: existing.remark ?? '',
      }
      if (existing.classroom) {
        loadingTables.value = true
        try {
          tables.value = await classroomTablesService.listByClassroom(existing.classroom.id)
        } finally {
          loadingTables.value = false
        }
      }
    }
  } catch (error) {
    lookupError.value = error instanceof ApiRequestError ? error.message : t('admin.exams.lookupFailed')
  } finally {
    lookingUp.value = false
  }
}

async function submit() {
  if (!lookup.value) return
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    if (canEditStudent.value) {
      await studentsService.updatePartial(lookup.value.student.id, {
        photo: photoFile.value ?? undefined,
        first_name: studentForm.value.first_name,
        last_name: studentForm.value.last_name,
        english_name: studentForm.value.english_name,
        gender: studentForm.value.gender,
        date_of_birth: studentForm.value.date_of_birth,
        phone: studentForm.value.phone,
        village_code: studentForm.value.village_code || undefined,
      })
    }

    const input: ExamApplicationInput = {
      book_id: examForm.value.book_id,
      exam_date: examForm.value.exam_date || null,
      exam_time: examForm.value.exam_time || null,
      exam_time_out: examForm.value.exam_time_out || null,
      classroom_id: examForm.value.classroom_id,
      table_id: examForm.value.table_id,
      remark: examForm.value.remark || null,
    } as ExamApplicationInput

    if (lookup.value.exam_application) {
      await examApplicationsService.update(lookup.value.exam_application.id, input)
    } else {
      await examApplicationsService.create(lookup.value.enrollment_id, input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.exams.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.exams.applicationForm')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <div class="mb-4 flex items-end gap-2">
      <BaseInput v-model="enrollmentCode" :label="t('admin.exams.enrollmentCode')" class="flex-1" @keyup.enter="showData" />
      <BaseButton :loading="lookingUp" variant="outline" @click="showData">{{ t('admin.exams.showData') }}</BaseButton>
    </div>

    <BaseAlert v-if="lookupError" variant="danger" class="mb-4">{{ lookupError }}</BaseAlert>
    <BaseAlert v-if="generalError" variant="danger" class="mb-4">{{ generalError }}</BaseAlert>

    <form v-if="lookup" class="space-y-8" @submit.prevent>
      <section>
        <h3 class="mb-1 border-b border-neutral-200 pb-2 text-sm font-semibold text-primary-800">{{ t('admin.exams.studentInformation') }}</h3>

        <p class="mt-3 text-sm text-neutral-500">{{ t('admin.exams.studentCode') }}: <strong>{{ lookup.student.student_code }}</strong></p>

        <div class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
          <BaseInput v-model="studentForm.first_name" :disabled="!canEditStudent" :label="t('admin.exams.firstName')" :error="errors.first_name?.[0]" />
          <BaseInput v-model="studentForm.last_name" :disabled="!canEditStudent" :label="t('admin.exams.lastName')" :error="errors.last_name?.[0]" />

          <BaseInput v-model="studentForm.english_name" :disabled="!canEditStudent" :label="t('admin.exams.otherName')" :error="errors.english_name?.[0]" />

          <div>
            <label class="mb-1 block text-sm font-medium text-neutral-700">{{ t('admin.exams.sex') }}</label>
            <div class="flex items-center gap-4 pt-2">
              <label class="flex items-center gap-1.5 text-sm text-neutral-700">
                <input v-model="studentForm.gender" type="radio" value="male" :disabled="!canEditStudent" />
                {{ t('admin.exams.genderMale') }}
              </label>
              <label class="flex items-center gap-1.5 text-sm text-neutral-700">
                <input v-model="studentForm.gender" type="radio" value="female" :disabled="!canEditStudent" />
                {{ t('admin.exams.genderFemale') }}
              </label>
            </div>
          </div>

          <BaseInput v-model="studentForm.date_of_birth" type="date" :disabled="!canEditStudent" :label="t('admin.exams.birthDate')" :error="errors.date_of_birth?.[0]" />
          <BaseInput v-model="studentForm.phone" :disabled="!canEditStudent" :label="t('admin.exams.phone')" :error="errors.phone?.[0]" />

          <AddressSelects v-model="studentForm.village_code" :disabled="!canEditStudent" />
        </div>

        <div v-if="canEditStudent" class="mt-4 flex items-center gap-4">
          <div class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-full border border-neutral-200 bg-neutral-100">
            <img v-if="photoPreview" :src="photoPreview" alt="" class="h-full w-full object-cover" />
            <svg v-else class="h-10 w-10 text-neutral-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0ZM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
            </svg>
          </div>
          <div class="flex flex-col gap-2">
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp,image/gif"
              class="block text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
              @change="onPhotoChange"
            />
            <BaseButton type="button" variant="outline" size="sm" @click="webcamModalOpen = true">{{ t('admin.students.takePhoto') }}</BaseButton>
          </div>
        </div>
      </section>

      <section>
        <h3 class="mb-1 border-b border-neutral-200 pb-2 text-sm font-semibold text-primary-800">{{ t('admin.exams.examinationInformation') }}</h3>

        <div class="mt-3 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
          <BaseInput :model-value="lookup.course_package?.name ?? ''" disabled :label="t('admin.exams.course')" />
          <BaseSelect
            :model-value="examForm.book_id !== null ? String(examForm.book_id) : ''"
            :options="bookOptions"
            :placeholder="t('admin.exams.selectBook')"
            :label="t('admin.exams.book')"
            :error="errors.book_id?.[0]"
            @update:model-value="examForm.book_id = $event ? Number($event) : null"
          />

          <BaseSelect
            :model-value="examForm.classroom_id !== null ? String(examForm.classroom_id) : ''"
            :options="classroomOptions"
            :placeholder="t('admin.exams.selectRoom')"
            :label="t('admin.exams.roomNumber')"
            :error="errors.classroom_id?.[0]"
            @update:model-value="onClassroomChange"
          />
          <BaseSelect
            :model-value="examForm.table_id !== null ? String(examForm.table_id) : ''"
            :options="tableOptions"
            :disabled="!examForm.classroom_id || loadingTables"
            :placeholder="loadingTables ? t('common.loading') : t('admin.exams.selectTable')"
            :label="t('admin.exams.tableNumber')"
            :error="errors.table_id?.[0]"
            @update:model-value="examForm.table_id = $event ? Number($event) : null"
          />

          <BaseInput v-model="examForm.exam_date" type="date" :label="t('admin.exams.examDate')" :error="errors.exam_date?.[0]" />
          <BaseInput v-model="examForm.remark" :label="t('admin.exams.remark')" :error="errors.remark?.[0]" />

          <BaseInput v-model="examForm.exam_time" type="time" :label="t('admin.exams.timeIn')" :error="errors.exam_time?.[0]" />
          <BaseInput v-model="examForm.exam_time_out" type="time" :label="t('admin.exams.timeOut')" :error="errors.exam_time_out?.[0]" />
        </div>
      </section>
    </form>

    <p v-else class="py-8 text-center text-sm text-neutral-400">{{ t('admin.exams.enterEnrollmentCodePrompt') }}</p>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton v-if="lookup" :loading="submitting" @click="submit">{{ t('admin.exams.submit') }}</BaseButton>
    </template>

    <WebcamCaptureModal v-model="webcamModalOpen" @captured="setPhotoFile" />
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { academicProgramsService, type AcademicProgram } from '@/services/academicPrograms'
import { classesService, type ClassInput } from '@/services/classes'
import { classroomsService, type Classroom } from '@/services/classrooms'
import { positionsService } from '@/services/positions'
import { staffService, type Staff } from '@/services/staff'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const classId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEditing = computed(() => classId.value !== null)

function emptySchedule() {
  return { day_of_week: 6, start_time: '', end_time: '' }
}

const form = reactive<ClassInput>({
  name: '',
  code: '',
  teacher_ids: [],
  assistant_teacher_ids: [],
  classroom_id: null,
  academic_program_id: null,
  start_date: '',
  end_date: '',
  status: 'active',
  schedules: [emptySchedule()],
})

const teachers = ref<Staff[]>([])
const classrooms = ref<Classroom[]>([])
const programs = ref<AcademicProgram[]>([])

const loading = ref(true)
const loadError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const dayOptions = computed(() => [
  { value: '1', label: t('admin.classes.monday') },
  { value: '2', label: t('admin.classes.tuesday') },
  { value: '3', label: t('admin.classes.wednesday') },
  { value: '4', label: t('admin.classes.thursday') },
  { value: '5', label: t('admin.classes.friday') },
  { value: '6', label: t('admin.classes.saturday') },
  { value: '7', label: t('admin.classes.sunday') },
])

const statusOptions = computed(() => [
  { value: 'upcoming', label: t('admin.classes.statusUpcoming') },
  { value: 'active', label: t('admin.classes.statusActive') },
  { value: 'completed', label: t('admin.classes.statusCompleted') },
  { value: 'cancelled', label: t('admin.classes.statusCancelled') },
])

const classroomOptions = computed(() => [
  { value: '', label: t('admin.classes.noClassroom') },
  ...classrooms.value.map((classroom) => ({ value: String(classroom.id), label: classroom.name })),
])

const programOptions = computed(() => [
  { value: '', label: t('admin.classes.noProgram') },
  ...programs.value.map((program) => ({ value: String(program.id), label: `${program.code} — ${program.name}` })),
])

// Mutually exclusive: picking someone as an assistant drops them from the
// teacher list and vice versa — a staff member can't hold both roles on the
// same class (the server enforces this too, see StoreSchoolClassRequest).
function toggleTeacher(staffId: number, checked: boolean) {
  if (checked) {
    if (!form.teacher_ids.includes(staffId)) form.teacher_ids.push(staffId)
    form.assistant_teacher_ids = form.assistant_teacher_ids.filter((id) => id !== staffId)
  } else {
    form.teacher_ids = form.teacher_ids.filter((id) => id !== staffId)
  }
}

function toggleAssistantTeacher(staffId: number, checked: boolean) {
  if (checked) {
    if (!form.assistant_teacher_ids.includes(staffId)) form.assistant_teacher_ids.push(staffId)
    form.teacher_ids = form.teacher_ids.filter((id) => id !== staffId)
  } else {
    form.assistant_teacher_ids = form.assistant_teacher_ids.filter((id) => id !== staffId)
  }
}

function addSchedule() {
  form.schedules.push(emptySchedule())
}

function removeSchedule(index: number) {
  form.schedules.splice(index, 1)
}

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const [positions, loadedClassrooms, loadedPrograms] = await Promise.all([
      positionsService.listAll(),
      classroomsService.listAll(),
      academicProgramsService.listAll(),
    ])
    classrooms.value = loadedClassrooms
    programs.value = loadedPrograms

    // A "teacher" is a Staff member holding a position that carries the
    // Teacher role — not necessarily named "Teacher" (a school's position
    // titles are free text, often translated, and there can be more than
    // one — e.g. "Computer Teacher" and "Teaching Assistant" might both
    // carry it). See Position::teacherPositionIds() on the backend.
    const teacherPositionIds = positions.filter((p) => p.role?.slug === 'teacher').map((p) => p.id)
    teachers.value = teacherPositionIds.length > 0 ? await staffService.listAll({ position_id: teacherPositionIds.join(',') }) : []

    if (!classId.value) return

    const schoolClass = await classesService.get(classId.value)
    form.name = schoolClass.name
    form.code = schoolClass.code ?? ''
    form.teacher_ids = schoolClass.teachers.map((t) => t.id)
    form.assistant_teacher_ids = schoolClass.assistant_teachers.map((t) => t.id)
    form.classroom_id = schoolClass.classroom?.id ?? null
    form.academic_program_id = schoolClass.academic_program?.id ?? null
    form.start_date = schoolClass.start_date ?? ''
    form.end_date = schoolClass.end_date ?? ''
    form.status = schoolClass.status
    form.schedules = schoolClass.schedules.length > 0
      ? schoolClass.schedules.map((s) => ({ day_of_week: s.day_of_week, start_time: s.start_time, end_time: s.end_time }))
      : [emptySchedule()]
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.classes.loadFailed')
  } finally {
    loading.value = false
  }
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    if (isEditing.value) {
      await classesService.update(classId.value!, form)
    } else {
      await classesService.create(form)
    }

    await router.push('/admin/classes')
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.classes.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-3xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">
        {{ isEditing ? t('admin.classes.editTitle') : t('admin.classes.addClass') }}
      </h1>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <form v-else class="space-y-10" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <section>
        <h2 class="mb-4 text-sm font-semibold text-neutral-800">{{ t('admin.classes.detailsSection') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.name" required :label="t('admin.classes.name')" :hint="t('admin.classes.nameHint')" :error="errors.name?.[0]" />
          <BaseInput v-model="form.code" :label="t('admin.classes.code')" :error="errors.code?.[0]" />
          <BaseSelect
            :model-value="form.classroom_id !== null ? String(form.classroom_id) : ''"
            :options="classroomOptions"
            :label="t('admin.classes.classroom')"
            @update:model-value="form.classroom_id = $event ? Number($event) : null"
          />
          <BaseSelect
            :model-value="form.academic_program_id !== null ? String(form.academic_program_id) : ''"
            :options="programOptions"
            :label="t('admin.classes.program')"
            :hint="t('admin.classes.programHint')"
            @update:model-value="form.academic_program_id = $event ? Number($event) : null"
          />
          <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.classes.status')" />
          <BaseInput v-model="form.start_date" type="date" :label="t('admin.classes.startDate')" :error="errors.start_date?.[0]" />
          <BaseInput v-model="form.end_date" type="date" :label="t('admin.classes.endDate')" :error="errors.end_date?.[0]" />
        </div>
      </section>

      <section>
        <h2 class="mb-1 text-sm font-semibold text-neutral-800">{{ t('admin.classes.teachersSection') }}</h2>
        <p class="mb-3 text-sm text-neutral-500">{{ t('admin.classes.teachersHint') }}</p>
        <p v-if="teachers.length === 0" class="text-sm text-neutral-500">{{ t('admin.classes.noTeachersAvailable') }}</p>
        <div v-else class="grid gap-4 sm:grid-cols-2">
          <div>
            <h3 class="mb-2 text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('admin.classes.teacher') }}</h3>
            <div class="grid gap-2">
              <label v-for="staff in teachers" :key="staff.id" class="flex items-center gap-2 rounded-lg border border-neutral-200 p-2.5 text-sm">
                <input
                  type="checkbox"
                  :checked="form.teacher_ids.includes(staff.id)"
                  class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                  @change="toggleTeacher(staff.id, ($event.target as HTMLInputElement).checked)"
                />
                <span class="flex-1 text-neutral-700">{{ staff.full_name }}</span>
              </label>
            </div>
            <p v-if="errors.teacher_ids?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.teacher_ids[0] }}</p>
          </div>
          <div>
            <h3 class="mb-2 text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('admin.classes.assistantTeacher') }}</h3>
            <div class="grid gap-2">
              <label v-for="staff in teachers" :key="staff.id" class="flex items-center gap-2 rounded-lg border border-neutral-200 p-2.5 text-sm">
                <input
                  type="checkbox"
                  :checked="form.assistant_teacher_ids.includes(staff.id)"
                  class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                  @change="toggleAssistantTeacher(staff.id, ($event.target as HTMLInputElement).checked)"
                />
                <span class="flex-1 text-neutral-700">{{ staff.full_name }}</span>
              </label>
            </div>
            <p v-if="errors.assistant_teacher_ids?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.assistant_teacher_ids[0] }}</p>
          </div>
        </div>
      </section>

      <section>
        <div class="mb-1 flex items-center justify-between">
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.classes.scheduleSection') }}</h2>
          <BaseButton type="button" variant="outline" size="sm" @click="addSchedule">{{ t('admin.classes.addSchedule') }}</BaseButton>
        </div>
        <p class="mb-4 text-sm text-neutral-500">{{ t('admin.classes.scheduleHint') }}</p>

        <div v-for="(schedule, index) in form.schedules" :key="index" class="mb-3 grid grid-cols-[1fr_1fr_1fr_auto] items-end gap-3 rounded-lg border border-neutral-200 p-3">
          <BaseSelect
            :model-value="String(schedule.day_of_week)"
            :options="dayOptions"
            :label="t('admin.classes.day')"
            @update:model-value="schedule.day_of_week = Number($event)"
          />
          <BaseInput v-model="schedule.start_time" type="time" :label="t('admin.classes.startTime')" :error="errors[`schedules.${index}.start_time`]?.[0]" />
          <BaseInput v-model="schedule.end_time" type="time" :label="t('admin.classes.endTime')" :error="errors[`schedules.${index}.end_time`]?.[0]" />
          <button type="button" class="mb-1.5 text-sm font-medium text-danger-600 hover:text-red-700" @click="removeSchedule(index)">
            {{ t('common.remove') }}
          </button>
        </div>
      </section>

      <div class="flex gap-3">
        <BaseButton type="submit" :loading="submitting">{{ t('common.save') }}</BaseButton>
        <BaseButton type="button" variant="outline" @click="router.push('/admin/classes')">{{ t('common.cancel') }}</BaseButton>
      </div>
    </form>
  </div>
</template>

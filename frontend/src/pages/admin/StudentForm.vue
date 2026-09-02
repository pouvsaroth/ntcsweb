<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import LookupSelect from '@/components/ui/LookupSelect.vue'
import WebcamCaptureModal from '@/components/ui/WebcamCaptureModal.vue'
import { geographyService, type GeographyOption } from '@/services/geography'
import { studentsService, type StudentEducation, type StudentGuardian, type StudentInput } from '@/services/students'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const studentId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEditing = computed(() => studentId.value !== null)

function emptyGuardian(): StudentGuardian {
  return { guardian_name: '', guardian_type: '', address: '', phone: '', email: '', remark: '' }
}

function emptyEducation(): StudentEducation {
  return { school_name: '', address: '', start_date: '', end_date: '', skill: '', detail: '' }
}

/** yyyy-MM-dd in the viewer's local time, matching a `type="date"` input's expected value. */
function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** Same as today(), N years back — used to pre-fill a plausible date of birth on a brand-new registration. */
function yearsAgo(years: number): string {
  const now = new Date()
  return `${now.getFullYear() - years}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** Thum Tboung, Ponhea Pon Sangkat, Praek Pnov Khan, Phnom Penh — this school's own default village, pre-selected on a new registration to save re-picking it every time; staff can still change any level. */
const DEFAULT_VILLAGE_CODE = '12110201'

const form = reactive<Omit<StudentInput, 'photo'>>({
  first_name: '',
  last_name: '',
  english_name: '',
  date_of_birth: '',
  gender: '',
  email: '',
  phone: '',
  house_no: '',
  street_no: '',
  village_code: '',
  other_address: '',
  facebook: '',
  telegram: '',
  // Defaults to today for a new registration; load() overwrites this with
  // the real value when editing an existing student.
  enrollment_date: today(),
  status: 'active',
  guardians: [],
  educations: [],
})

const photoFile = ref<File | null>(null)
const photoPreview = ref<string | null>(null)
const webcamModalOpen = ref(false)
/** Read-only, display-only — server-generated (see StudentIdGenerator on the backend), never part of the submitted form. */
const studentCode = ref<string | null>(null)
const loading = ref(isEditing.value)
const loadError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const statusOptions = computed(() => [
  { value: 'active', label: t('admin.students.statusActive') },
  { value: 'graduated', label: t('admin.students.statusGraduated') },
  { value: 'withdrawn', label: t('admin.students.statusWithdrawn') },
  { value: 'inactive', label: t('admin.students.statusInactive') },
])

// Cambodia's official Province > District > Commune > Village hierarchy —
// selecting a village is what actually sets form.village_code. Each level's
// options only exist once its parent is chosen, and choosing a level resets
// everything below it (a new province means the old district no longer
// makes sense).
const provinces = ref<GeographyOption[]>([])
const districts = ref<GeographyOption[]>([])
const communes = ref<GeographyOption[]>([])
const villages = ref<GeographyOption[]>([])

const selectedProvinceId = ref('')
const selectedDistrictId = ref('')
const selectedCommuneId = ref('')
const selectedVillageId = ref('')

function toOptions(rows: GeographyOption[]) {
  return rows.map((row) => ({ value: String(row.id), label: `${row.name_km} — ${row.name_latin}` }))
}

const provinceOptions = computed(() => toOptions(provinces.value))
const districtOptions = computed(() => toOptions(districts.value))
const communeOptions = computed(() => toOptions(communes.value))
const villageOptions = computed(() => toOptions(villages.value))

async function onProvinceChange(value: string) {
  selectedProvinceId.value = value
  selectedDistrictId.value = ''
  selectedCommuneId.value = ''
  selectedVillageId.value = ''
  districts.value = []
  communes.value = []
  villages.value = []
  form.village_code = ''

  if (value) districts.value = await geographyService.districts(Number(value))
}

async function onDistrictChange(value: string) {
  selectedDistrictId.value = value
  selectedCommuneId.value = ''
  selectedVillageId.value = ''
  communes.value = []
  villages.value = []
  form.village_code = ''

  if (value) communes.value = await geographyService.communes(Number(value))
}

async function onCommuneChange(value: string) {
  selectedCommuneId.value = value
  selectedVillageId.value = ''
  villages.value = []
  form.village_code = ''

  if (value) villages.value = await geographyService.villages(Number(value))
}

function onVillageChange(value: string) {
  selectedVillageId.value = value
  form.village_code = villages.value.find((v) => String(v.id) === value)?.code ?? ''
}

/** Pre-selects all four dropdowns for a student that already has a village_code. */
async function selectAddressFromVillageCode(code: string) {
  const ancestry = await geographyService.lookup(code)

  selectedProvinceId.value = String(ancestry.province.id)
  districts.value = await geographyService.districts(ancestry.province.id)

  selectedDistrictId.value = String(ancestry.district.id)
  communes.value = await geographyService.communes(ancestry.district.id)

  selectedCommuneId.value = String(ancestry.commune.id)
  villages.value = await geographyService.villages(ancestry.commune.id)

  selectedVillageId.value = String(ancestry.village.id)
}

function addGuardian() {
  form.guardians.push(emptyGuardian())
}

function removeGuardian(index: number) {
  form.guardians.splice(index, 1)
}

function addEducation() {
  form.educations.push(emptyEducation())
}

function removeEducation(index: number) {
  form.educations.splice(index, 1)
}

function setPhotoFile(file: File) {
  photoFile.value = file
  photoPreview.value = URL.createObjectURL(file)
}

function onPhotoChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  setPhotoFile(file)
}

async function load() {
  loading.value = true
  loadError.value = null

  try {
    provinces.value = await geographyService.provinces()

    if (!studentId.value) {
      // Sensible starting defaults for a brand-new registration — every
      // field here stays fully editable, this just saves re-entering the
      // most common case each time.
      form.gender = 'female'
      form.date_of_birth = yearsAgo(13)
      form.village_code = DEFAULT_VILLAGE_CODE
      await selectAddressFromVillageCode(DEFAULT_VILLAGE_CODE)
      return
    }

    const student = await studentsService.get(studentId.value)
    studentCode.value = student.student_code
    form.first_name = student.first_name
    form.last_name = student.last_name
    form.english_name = student.english_name ?? ''
    form.date_of_birth = student.date_of_birth ?? ''
    form.gender = student.gender ?? ''
    form.email = student.email ?? ''
    form.phone = student.phone ?? ''
    form.house_no = student.house_no ?? ''
    form.street_no = student.street_no ?? ''
    form.village_code = student.village_code ?? ''
    form.other_address = student.other_address ?? ''
    form.facebook = student.facebook ?? ''
    form.telegram = student.telegram ?? ''
    form.enrollment_date = student.enrollment_date ?? ''
    form.status = student.status
    form.guardians = student.guardians ?? []
    form.educations = student.educations ?? []
    photoPreview.value = student.photo_url

    if (student.village_code) await selectAddressFromVillageCode(student.village_code)
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.students.loadFailed')
  } finally {
    loading.value = false
  }
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const payload = { ...form, photo: photoFile.value ?? undefined }

  try {
    if (isEditing.value) {
      await studentsService.update(studentId.value!, payload)
    } else {
      await studentsService.create(payload)
    }

    await router.push('/admin/students')
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.students.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}

/** Field-level errors for one row of a nested array, e.g. "guardians.0.phone". */
function rowError(collection: 'guardians' | 'educations', index: number, field: string): string | undefined {
  return errors.value[`${collection}.${index}.${field}`]?.[0]
}

onMounted(load)
</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">
        {{ isEditing ? t('admin.students.editTitle') : t('admin.students.registerTitle') }}
      </h1>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <form v-else class="space-y-10" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <!-- Student information -->
      <section>
        <h2 class="mb-1 border-b border-neutral-200 pb-2 text-sm font-semibold text-primary-800">{{ t('admin.students.studentSection') }}</h2>

        <div class="mt-4 flex w-full flex-col items-center gap-3">
          <BaseButton type="button" variant="outline" size="sm" @click="webcamModalOpen = true">
            {{ t('admin.students.takePhoto') }}
          </BaseButton>

          <div class="flex h-28 w-28 items-center justify-center overflow-hidden rounded-full border border-neutral-200 bg-neutral-100">
            <img v-if="photoPreview" :src="photoPreview" alt="" class="h-full w-full object-cover" />
            <svg v-else class="h-12 w-12 text-neutral-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0ZM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
            </svg>
          </div>

          <input
            type="file"
            accept="image/jpeg,image/png,image/webp,image/gif"
            class="block text-center text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
            @change="onPhotoChange"
          />
          <p v-if="errors.photo?.[0]" class="text-sm text-danger-600">{{ errors.photo[0] }}</p>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
          <!-- Read-only: the Student ID is generated by the backend (see
               StudentIdGenerator) — never editable here, on create or edit. -->
          <BaseInput
            v-if="isEditing"
            :model-value="studentCode ?? ''"
            disabled
            :label="t('admin.students.studentCode')"
            :hint="t('admin.students.studentCodeHint')"
          />

          <BaseInput v-model="form.first_name" required :label="t('admin.students.firstName')" :error="errors.first_name?.[0]" />
          <BaseInput v-model="form.last_name" required :label="t('admin.students.lastName')" :error="errors.last_name?.[0]" />
          <BaseInput v-model="form.english_name" :label="t('admin.students.englishName')" :error="errors.english_name?.[0]" />
          <LookupSelect
            v-model="form.gender"
            category="GENDER"
            :label="t('admin.students.gender')"
            :placeholder="t('admin.students.selectGender')"
            :error="errors.gender?.[0]"
          />

          <BaseInput v-model="form.date_of_birth" type="date" :label="t('admin.students.dateOfBirth')" :error="errors.date_of_birth?.[0]" />
          <BaseInput v-model="form.enrollment_date" type="date" :label="t('admin.students.enrollmentDate')" :error="errors.enrollment_date?.[0]" />

          <BaseInput v-model="form.phone" required :label="t('admin.students.phone')" :error="errors.phone?.[0]" />
          <BaseInput v-model="form.email" type="email" :label="t('admin.students.email')" :error="errors.email?.[0]" />

          <BaseInput v-model="form.house_no" :label="t('admin.students.houseNo')" :error="errors.house_no?.[0]" />
          <BaseInput v-model="form.street_no" :label="t('admin.students.streetNo')" :error="errors.street_no?.[0]" />

          <BaseInput v-model="form.facebook" :label="t('admin.students.facebook')" :error="errors.facebook?.[0]" />
          <BaseInput v-model="form.telegram" :label="t('admin.students.telegram')" :error="errors.telegram?.[0]" />

          <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.students.status')" />
        </div>

        <div class="mt-4">
          <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('admin.students.address') }}</p>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <BaseSelect
              :model-value="selectedProvinceId"
              :options="provinceOptions"
              :placeholder="t('admin.students.selectProvince')"
              :label="t('admin.students.province')"
              @update:model-value="onProvinceChange"
            />
            <BaseSelect
              :model-value="selectedDistrictId"
              :options="districtOptions"
              :disabled="!selectedProvinceId"
              :placeholder="t('admin.students.selectDistrict')"
              :label="t('admin.students.district')"
              @update:model-value="onDistrictChange"
            />
            <BaseSelect
              :model-value="selectedCommuneId"
              :options="communeOptions"
              :disabled="!selectedDistrictId"
              :placeholder="t('admin.students.selectCommune')"
              :label="t('admin.students.commune')"
              @update:model-value="onCommuneChange"
            />
            <BaseSelect
              :model-value="selectedVillageId"
              :options="villageOptions"
              :disabled="!selectedCommuneId"
              :placeholder="t('admin.students.selectVillage')"
              :label="t('admin.students.village')"
              :error="errors.village_code?.[0]"
              @update:model-value="onVillageChange"
            />
          </div>
          <BaseInput v-model="form.other_address" class="mt-3" :label="t('admin.students.otherAddress')" :error="errors.other_address?.[0]" />
        </div>
      </section>

      <WebcamCaptureModal v-model="webcamModalOpen" @captured="setPhotoFile" />

      <!-- Guardians -->
      <section>
        <div class="mb-1 flex items-center justify-between border-b border-neutral-200 pb-2">
          <h2 class="text-sm font-semibold text-primary-800">{{ t('admin.students.guardiansSection') }}</h2>
          <BaseButton type="button" variant="outline" size="sm" @click="addGuardian">{{ t('admin.students.addGuardian') }}</BaseButton>
        </div>
        <p class="mb-4 text-sm text-neutral-500">{{ t('admin.students.guardiansHint') }}</p>

        <p v-if="form.guardians.length === 0" class="rounded-lg border border-dashed border-neutral-300 p-4 text-center text-sm text-neutral-500">
          {{ t('admin.students.noGuardians') }}
        </p>

        <div v-for="(guardian, index) in form.guardians" :key="index" class="mb-4 rounded-lg border border-neutral-200 p-4">
          <div class="mb-3 flex items-center justify-between">
            <p class="text-sm font-medium text-neutral-700">{{ t('admin.students.guardianN', { n: index + 1 }) }}</p>
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeGuardian(index)">
              {{ t('common.remove') }}
            </button>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <BaseInput v-model="guardian.guardian_name" required :label="t('admin.students.guardianName')" :error="rowError('guardians', index, 'guardian_name')" />
            <LookupSelect
              v-model="guardian.guardian_type"
              category="GUARDIAN_TYPE"
              required
              :label="t('admin.students.guardianType')"
              :placeholder="t('admin.students.selectGuardianType')"
              :error="rowError('guardians', index, 'guardian_type')"
            />
            <BaseInput v-model="guardian.phone" required :label="t('admin.students.guardianPhone')" :error="rowError('guardians', index, 'phone')" />
            <BaseInput v-model="guardian.email" type="email" :label="t('admin.students.guardianEmail')" :error="rowError('guardians', index, 'email')" />
            <BaseInput v-model="guardian.address" class="sm:col-span-2" :label="t('admin.students.guardianAddress')" :error="rowError('guardians', index, 'address')" />
            <BaseInput v-model="guardian.remark" class="sm:col-span-2" :label="t('admin.students.guardianRemark')" :error="rowError('guardians', index, 'remark')" />
          </div>
        </div>
      </section>

      <!-- Education history -->
      <section>
        <div class="mb-1 flex items-center justify-between border-b border-neutral-200 pb-2">
          <h2 class="text-sm font-semibold text-primary-800">{{ t('admin.students.educationSection') }}</h2>
          <BaseButton type="button" variant="outline" size="sm" @click="addEducation">{{ t('admin.students.addEducation') }}</BaseButton>
        </div>
        <p class="mb-4 text-sm text-neutral-500">{{ t('admin.students.educationHint') }}</p>

        <p v-if="form.educations.length === 0" class="rounded-lg border border-dashed border-neutral-300 p-4 text-center text-sm text-neutral-500">
          {{ t('admin.students.noEducation') }}
        </p>

        <div v-for="(education, index) in form.educations" :key="index" class="mb-4 rounded-lg border border-neutral-200 p-4">
          <div class="mb-3 flex items-center justify-between">
            <p class="text-sm font-medium text-neutral-700">{{ t('admin.students.educationN', { n: index + 1 }) }}</p>
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeEducation(index)">
              {{ t('common.remove') }}
            </button>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <BaseInput v-model="education.school_name" required :label="t('admin.students.schoolName')" :error="rowError('educations', index, 'school_name')" />
            <BaseInput v-model="education.address" required :label="t('admin.students.schoolAddress')" :error="rowError('educations', index, 'address')" />
            <BaseInput v-model="education.start_date" type="date" required :label="t('admin.students.startDate')" :error="rowError('educations', index, 'start_date')" />
            <BaseInput v-model="education.end_date" type="date" :label="t('admin.students.endDate')" :hint="t('admin.students.endDateHint')" :error="rowError('educations', index, 'end_date')" />
            <BaseInput v-model="education.skill" required :label="t('admin.students.skill')" :error="rowError('educations', index, 'skill')" />
            <BaseInput v-model="education.detail" required :label="t('admin.students.detail')" :error="rowError('educations', index, 'detail')" />
          </div>
        </div>
      </section>

      <div class="flex gap-3">
        <BaseButton type="submit" :loading="submitting">{{ t('common.save') }}</BaseButton>
        <BaseButton type="button" variant="outline" @click="router.push('/admin/students')">{{ t('common.cancel') }}</BaseButton>
      </div>
    </form>
  </div>
</template>

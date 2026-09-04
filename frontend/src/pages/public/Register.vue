<script setup lang="ts">
import QRCode from 'qrcode'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import WebcamCaptureModal from '@/components/ui/WebcamCaptureModal.vue'
import PageHero from '@/components/public/PageHero.vue'
import SectionContainer from '@/components/public/SectionContainer.vue'
import { publicContentService, type PublicCourse, type ScheduledClass } from '@/services/publicContent'
import { publicGeographyService } from '@/services/publicGeography'
import type { GeographyOption } from '@/services/geography'
import { studentSelfRegistrationService } from '@/services/studentSelfRegistration'
import { useSiteStore } from '@/stores/site'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const site = useSiteStore()

type FeeType = 'monthly' | 'term' | 'video' | 'monthly_online' | 'term_online'

const STEP_COUNT = 5
const step = ref(1)

const form = reactive({
  first_name: '',
  last_name: '',
  gender: '',
  date_of_birth: '',
  phone: '',
  email: '',
  house_no: '',
  street_no: '',
  other_address: '',
  village_code: '',
  class_id: null as number | null,
  course_package_id: null as number | null,
  fee_type: '' as FeeType | '',
  payment_method: 'CASH' as 'CASH' | 'QR',
  password: '',
  password_confirmation: '',
})

const genderOptions = computed(() => [
  { value: 'male', label: t('registerWizard.genderMale') },
  { value: 'female', label: t('registerWizard.genderFemale') },
  { value: 'other', label: t('registerWizard.genderOther') },
])

// --- Step 1: photo -----------------------------------------------------

const photoFile = ref<File | null>(null)
const photoPreview = ref<string | null>(null)
const webcamModalOpen = ref(false)

function onPhotoChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  photoFile.value = file
  photoPreview.value = URL.createObjectURL(file)
}

function onWebcamCaptured(file: File) {
  photoFile.value = file
  photoPreview.value = URL.createObjectURL(file)
}

// --- Step 2: address -----------------------------------------------------
// Same cascading Province > District > Commune > Village pattern as the
// admin Student form (see StudentForm.vue) — public geography endpoints,
// since no one is signed in yet.

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

  if (value) districts.value = await publicGeographyService.districts(Number(value))
}

async function onDistrictChange(value: string) {
  selectedDistrictId.value = value
  selectedCommuneId.value = ''
  selectedVillageId.value = ''
  communes.value = []
  villages.value = []
  form.village_code = ''

  if (value) communes.value = await publicGeographyService.communes(Number(value))
}

async function onCommuneChange(value: string) {
  selectedCommuneId.value = value
  selectedVillageId.value = ''
  villages.value = []
  form.village_code = ''

  if (value) villages.value = await publicGeographyService.villages(Number(value))
}

function onVillageChange(value: string) {
  selectedVillageId.value = value
  form.village_code = villages.value.find((v) => String(v.id) === value)?.code ?? ''
}

// --- Step 3: program / course package / schedule -----------------------

const courses = ref<PublicCourse[]>([])
const coursesLoading = ref(true)
const selectedProgramId = ref('')
const classes = ref<ScheduledClass[]>([])
const classesLoading = ref(false)

const programOptions = computed(() => {
  const seen = new Map<number, { id: number; name: string; sort_order: number }>()
  for (const course of courses.value) {
    if (course.academic_program && !seen.has(course.academic_program.id)) {
      seen.set(course.academic_program.id, course.academic_program)
    }
  }
  return [...seen.values()]
    .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
    .map((p) => ({ value: String(p.id), label: p.name }))
})

const packageOptions = computed(() =>
  courses.value
    .filter((course) => String(course.academic_program?.id) === selectedProgramId.value)
    .map((course) => ({ value: String(course.id), label: course.name })),
)

const selectedPackage = computed(() => courses.value.find((course) => course.id === form.course_package_id) ?? null)

const dayLabel: Record<number, string> = {
  1: 'schedule.monday',
  2: 'schedule.tuesday',
  3: 'schedule.wednesday',
  4: 'schedule.thursday',
  5: 'schedule.friday',
  6: 'schedule.saturday',
  7: 'schedule.sunday',
}

function scheduleSummary(schoolClass: ScheduledClass): string {
  if (schoolClass.schedules.length === 0) return schoolClass.name
  const slots = schoolClass.schedules.map((slot) => `${t(dayLabel[slot.day_of_week])} ${slot.start_time.slice(0, 5)}–${slot.end_time.slice(0, 5)}`)
  return `${schoolClass.name} — ${slots.join(', ')}`
}

const classOptions = computed(() => classes.value.map((c) => ({ value: String(c.id), label: scheduleSummary(c) })))

async function onProgramChange(value: string) {
  selectedProgramId.value = value
  form.course_package_id = null
  form.class_id = null
  form.fee_type = ''
  classes.value = []
}

const feeTypePriority: FeeType[] = ['monthly', 'term', 'video', 'monthly_online', 'term_online']

function packageFee(pkg: PublicCourse, feeType: FeeType): number | null {
  return pkg[`fee_${feeType}`]
}

async function onPackageChange(value: string) {
  form.course_package_id = value ? Number(value) : null
  form.class_id = null
  form.fee_type = ''
  classes.value = []
  khqrString.value = null

  if (!value) return

  const pkg = selectedPackage.value
  if (pkg) {
    form.fee_type = packageFee(pkg, 'term') !== null ? 'term' : (feeTypePriority.find((type) => packageFee(pkg, type) !== null) ?? '')
  }

  classesLoading.value = true
  try {
    classes.value = await publicContentService.getCourseClasses(Number(value))
  } finally {
    classesLoading.value = false
  }
}

// --- Step 4: payment -----------------------------------------------------

const feeTypeLabels: Record<FeeType, string> = {
  monthly: 'registerWizard.feeTypeMonthly',
  term: 'registerWizard.feeTypeTerm',
  video: 'registerWizard.feeTypeVideo',
  monthly_online: 'registerWizard.feeTypeMonthlyOnline',
  term_online: 'registerWizard.feeTypeTermOnline',
}

const feeTypeOptions = computed(() => {
  if (!selectedPackage.value) return []
  return feeTypePriority
    .filter((type) => packageFee(selectedPackage.value!, type) !== null)
    .map((type) => ({ value: type, label: t(feeTypeLabels[type]) }))
})

const feeAmount = computed(() => (selectedPackage.value && form.fee_type ? (packageFee(selectedPackage.value, form.fee_type) ?? 0) : 0))
const currency = computed(() => selectedPackage.value?.currency ?? 'USD')
const currencySymbol = computed(() => (currency.value === 'USD' ? '$' : '៛'))

const khqrString = ref<string | null>(null)
const khqrImage = ref<string | null>(null)
const khqrLoading = ref(false)
const khqrError = ref<string | null>(null)

async function loadKhqr() {
  if (form.payment_method !== 'QR' || feeAmount.value <= 0) return

  khqrLoading.value = true
  khqrError.value = null
  khqrImage.value = null

  try {
    const result = await publicContentService.getKhqrPreview(feeAmount.value, currency.value)
    khqrString.value = result.khqr_string
    khqrImage.value = await QRCode.toDataURL(result.khqr_string, { width: 260, margin: 1 })
  } catch (error) {
    khqrError.value = error instanceof ApiRequestError ? error.message : t('registerWizard.khqrLoadFailed')
  } finally {
    khqrLoading.value = false
  }
}

watch(() => [form.payment_method, form.fee_type, form.course_package_id] as const, loadKhqr)

// --- Wizard navigation -----------------------------------------------------

const stepErrors = ref<string | null>(null)

const canProceedFromStep: Record<number, () => boolean> = {
  1: () => form.first_name.trim() !== '' && form.last_name.trim() !== '' && form.phone.trim() !== '',
  2: () => true,
  3: () => form.class_id !== null && form.course_package_id !== null && form.fee_type !== '',
  4: () => form.payment_method === 'CASH' || khqrString.value !== null,
  5: () => true,
}

function next() {
  stepErrors.value = null
  if (!canProceedFromStep[step.value]()) {
    stepErrors.value = t('registerWizard.completeRequiredFields')
    return
  }
  step.value = Math.min(STEP_COUNT, step.value + 1)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function back() {
  stepErrors.value = null
  step.value = Math.max(1, step.value - 1)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// --- Submit -----------------------------------------------------

const submitting = ref(false)
const generalError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const registeredCode = ref<string | null>(null)

async function submit() {
  if (form.class_id === null || form.course_package_id === null || form.fee_type === '') return

  submitting.value = true
  generalError.value = null
  errors.value = {}

  try {
    const result = await studentSelfRegistrationService.submit({
      first_name: form.first_name,
      last_name: form.last_name,
      gender: form.gender,
      date_of_birth: form.date_of_birth,
      phone: form.phone,
      email: form.email,
      house_no: form.house_no,
      street_no: form.street_no,
      village_code: form.village_code,
      other_address: form.other_address,
      photo: photoFile.value ?? undefined,
      class_id: form.class_id,
      course_package_id: form.course_package_id,
      fee_type: form.fee_type,
      payment_method: form.payment_method,
      password: form.password,
      password_confirmation: form.password_confirmation,
    })

    registeredCode.value = result.student_code
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
      generalError.value = t('registerWizard.fixErrorsBelow')
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('registerWizard.submitFailed')
    }
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  coursesLoading.value = true
  try {
    const result = await publicContentService.getCourses()
    courses.value = result.data
  } finally {
    coursesLoading.value = false
  }
})
</script>

<template>
  <div>
    <PageHero :title="t('registerWizard.title')" :subtitle="t('registerWizard.subtitle')" />
    <SectionContainer>
      <div v-if="registeredCode" class="mx-auto max-w-lg text-center">
        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-success-100">
          <svg class="h-8 w-8 text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
          </svg>
        </div>
        <h2 class="text-xl font-bold text-neutral-900">{{ t('registerWizard.successTitle') }}</h2>
        <p class="mt-2 text-neutral-600">
          {{ form.payment_method === 'QR' ? t('registerWizard.successMessageQr') : t('registerWizard.successMessageCash') }}
        </p>
        <p class="mt-4 text-sm text-neutral-500">{{ t('registerWizard.yourStudentCode') }}: <span class="font-mono font-semibold text-neutral-800">{{ registeredCode }}</span></p>
      </div>

      <div v-else class="mx-auto max-w-2xl">
        <!-- Step indicator -->
        <div class="mb-8 flex items-center justify-center gap-2">
          <template v-for="n in STEP_COUNT" :key="n">
            <div
              class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
              :class="n <= step ? 'bg-primary-600 text-white' : 'bg-neutral-200 text-neutral-500'"
            >
              {{ n }}
            </div>
            <div v-if="n < STEP_COUNT" class="h-0.5 w-6 shrink-0" :class="n < step ? 'bg-primary-600' : 'bg-neutral-200'" />
          </template>
        </div>

        <BaseAlert v-if="stepErrors" variant="danger" class="mb-4">{{ stepErrors }}</BaseAlert>
        <BaseAlert v-if="generalError" variant="danger" class="mb-4">{{ generalError }}</BaseAlert>

        <!-- Step 1: Student information -->
        <div v-if="step === 1" class="space-y-4">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('registerWizard.step1Title') }}</h2>

          <div class="flex flex-col items-center gap-3">
            <div class="flex h-28 w-28 items-center justify-center overflow-hidden rounded-full border border-neutral-200 bg-neutral-100">
              <img v-if="photoPreview" :src="photoPreview" alt="" class="h-full w-full object-cover" />
              <svg v-else class="h-12 w-12 text-neutral-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0ZM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
              </svg>
            </div>
            <div class="flex gap-2">
              <BaseButton type="button" variant="outline" size="sm" @click="webcamModalOpen = true">{{ t('registerWizard.takePhoto') }}</BaseButton>
              <label class="cursor-pointer">
                <span class="inline-flex items-center rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                  {{ t('registerWizard.uploadPhoto') }}
                </span>
                <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" @change="onPhotoChange" />
              </label>
            </div>
            <p v-if="errors.photo?.[0]" class="text-sm text-danger-600">{{ errors.photo[0] }}</p>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput v-model="form.first_name" required :label="t('registerWizard.firstName')" :error="errors.first_name?.[0]" />
            <BaseInput v-model="form.last_name" required :label="t('registerWizard.lastName')" :error="errors.last_name?.[0]" />
            <BaseSelect v-model="form.gender" :options="genderOptions" :label="t('registerWizard.gender')" :placeholder="t('registerWizard.selectGender')" :error="errors.gender?.[0]" />
            <BaseInput v-model="form.date_of_birth" type="date" :label="t('registerWizard.dateOfBirth')" :error="errors.date_of_birth?.[0]" />
            <BaseInput v-model="form.phone" required :label="t('registerWizard.phone')" :error="errors.phone?.[0]" />
            <BaseInput v-model="form.email" type="email" :label="t('registerWizard.email')" :error="errors.email?.[0]" />
          </div>
        </div>

        <!-- Step 2: Address -->
        <div v-else-if="step === 2" class="space-y-4">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('registerWizard.step2Title') }}</h2>

          <div class="grid gap-3 sm:grid-cols-2">
            <BaseSelect
              :model-value="selectedProvinceId"
              :options="provinceOptions"
              :placeholder="t('registerWizard.selectProvince')"
              :label="t('registerWizard.province')"
              @update:model-value="onProvinceChange"
            />
            <BaseSelect
              :model-value="selectedDistrictId"
              :options="districtOptions"
              :disabled="!selectedProvinceId"
              :placeholder="t('registerWizard.selectDistrict')"
              :label="t('registerWizard.district')"
              @update:model-value="onDistrictChange"
            />
            <BaseSelect
              :model-value="selectedCommuneId"
              :options="communeOptions"
              :disabled="!selectedDistrictId"
              :placeholder="t('registerWizard.selectCommune')"
              :label="t('registerWizard.commune')"
              @update:model-value="onCommuneChange"
            />
            <BaseSelect
              :model-value="selectedVillageId"
              :options="villageOptions"
              :disabled="!selectedCommuneId"
              :placeholder="t('registerWizard.selectVillage')"
              :label="t('registerWizard.village')"
              :error="errors.village_code?.[0]"
              @update:model-value="onVillageChange"
            />
          </div>
          <BaseInput v-model="form.house_no" :label="t('registerWizard.houseNo')" :error="errors.house_no?.[0]" />
          <BaseInput v-model="form.street_no" :label="t('registerWizard.streetNo')" :error="errors.street_no?.[0]" />
          <BaseInput v-model="form.other_address" :label="t('registerWizard.otherAddress')" :error="errors.other_address?.[0]" />
        </div>

        <!-- Step 3: Program information -->
        <div v-else-if="step === 3" class="space-y-4">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('registerWizard.step3Title') }}</h2>

          <BaseSpinner v-if="coursesLoading" class="mx-auto" />
          <template v-else>
            <BaseSelect
              :model-value="selectedProgramId"
              :options="programOptions"
              :placeholder="t('registerWizard.selectProgram')"
              :label="t('registerWizard.program')"
              @update:model-value="onProgramChange"
            />
            <BaseSelect
              :model-value="form.course_package_id !== null ? String(form.course_package_id) : ''"
              :options="packageOptions"
              :disabled="!selectedProgramId"
              :placeholder="t('registerWizard.selectCourse')"
              :label="t('registerWizard.course')"
              :error="errors.course_package_id?.[0]"
              @update:model-value="onPackageChange"
            />
            <BaseSpinner v-if="classesLoading" class="mx-auto" />
            <BaseSelect
              v-else
              :model-value="form.class_id !== null ? String(form.class_id) : ''"
              :options="classOptions"
              :disabled="!form.course_package_id"
              :placeholder="classOptions.length === 0 && form.course_package_id ? t('registerWizard.noScheduleAvailable') : t('registerWizard.selectSchedule')"
              :label="t('registerWizard.schedule')"
              :error="errors.class_id?.[0]"
              @update:model-value="(v: string) => (form.class_id = v ? Number(v) : null)"
            />
          </template>
        </div>

        <!-- Step 4: Payment -->
        <div v-else-if="step === 4" class="space-y-4">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('registerWizard.step4Title') }}</h2>

          <BaseSelect
            :model-value="form.fee_type"
            :options="feeTypeOptions"
            :label="t('registerWizard.feeType')"
            :error="errors.fee_type?.[0]"
            @update:model-value="(v: string) => (form.fee_type = v as FeeType)"
          />

          <div class="flex items-center justify-between rounded-lg bg-primary-50 px-4 py-3">
            <span class="text-sm font-medium text-neutral-700">{{ t('registerWizard.feeToPay') }}</span>
            <span class="text-lg font-bold text-primary-800">{{ currencySymbol }}{{ feeAmount.toFixed(2) }}</span>
          </div>

          <div>
            <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('registerWizard.paymentMethod') }}</p>
            <div class="flex gap-3">
              <label class="flex flex-1 cursor-pointer items-center gap-2 rounded-lg border border-neutral-300 p-3 text-sm" :class="form.payment_method === 'CASH' ? 'border-primary-500 bg-primary-50' : ''">
                <input v-model="form.payment_method" type="radio" value="CASH" class="text-primary-600 focus:ring-primary-500" />
                {{ t('registerWizard.paymentCash') }}
              </label>
              <label
                v-if="site.info.has_khqr"
                class="flex flex-1 cursor-pointer items-center gap-2 rounded-lg border border-neutral-300 p-3 text-sm"
                :class="form.payment_method === 'QR' ? 'border-primary-500 bg-primary-50' : ''"
              >
                <input v-model="form.payment_method" type="radio" value="QR" class="text-primary-600 focus:ring-primary-500" />
                {{ t('registerWizard.paymentQr') }}
              </label>
            </div>
          </div>

          <div v-if="form.payment_method === 'QR'" class="flex flex-col items-center gap-3 rounded-lg border border-neutral-200 p-4">
            <BaseSpinner v-if="khqrLoading" />
            <BaseAlert v-else-if="khqrError" variant="danger">{{ khqrError }}</BaseAlert>
            <template v-else-if="khqrImage">
              <img :src="khqrImage" alt="KHQR" class="h-64 w-64" />
              <p class="text-center text-sm text-neutral-500">{{ t('registerWizard.khqrHint') }}</p>
            </template>
          </div>
        </div>

        <!-- Step 5: Create password -->
        <div v-else-if="step === 5" class="space-y-4">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('registerWizard.step5Title') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('registerWizard.step5Hint') }}</p>

          <BaseInput v-model="form.password" type="password" required autocomplete="new-password" :label="t('registerWizard.password')" :error="errors.password?.[0]" />
          <BaseInput
            v-model="form.password_confirmation"
            type="password"
            required
            autocomplete="new-password"
            :label="t('registerWizard.confirmPassword')"
            :error="errors.password_confirmation?.[0]"
          />
        </div>

        <div class="mt-8 flex justify-between">
          <BaseButton v-if="step > 1" type="button" variant="outline" @click="back">{{ t('registerWizard.back') }}</BaseButton>
          <div v-else />

          <BaseButton v-if="step < STEP_COUNT" type="button" @click="next">{{ t('registerWizard.next') }}</BaseButton>
          <BaseButton
            v-else
            type="button"
            :loading="submitting"
            :disabled="!form.password || form.password !== form.password_confirmation"
            @click="submit"
          >
            {{ t('common.save') }}
          </BaseButton>
        </div>
      </div>
    </SectionContainer>

    <WebcamCaptureModal v-model="webcamModalOpen" @captured="onWebcamCaptured" />
  </div>
</template>

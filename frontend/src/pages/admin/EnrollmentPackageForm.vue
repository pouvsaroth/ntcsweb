<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import LookupSelect from '@/components/ui/LookupSelect.vue'
import { academicProgramsService, type AcademicProgram } from '@/services/academicPrograms'
import { classesService, type SchoolClass } from '@/services/classes'
import { coursePackagesService, type CoursePackage } from '@/services/coursePackages'
import { enrollmentsService, type FeeType } from '@/services/enrollments'
import { paymentMethods, type PaymentMethodValue } from '@/services/payments'
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

const programs = ref<AcademicProgram[]>([])
const classes = ref<SchoolClass[]>([])
const packages = ref<CoursePackage[]>([])
const loading = ref(true)

const form = reactive({
  academic_program_id: null as number | null,
  class_id: null as number | null,
  course_package_id: null as number | null,
  table_id: null as number | null,
  enrolled_at: today(),
  fee_type: null as FeeType | null,
  discount_reason: '',
  discount_price: 0,
  received_amount: 0,
  payment_method: 'CASH' as PaymentMethodValue,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const programOptions = computed(() => programs.value.map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` })))

// A class is just a schedule/room/teacher — it doesn't need to "offer" the
// package itself, only belong to the chosen program (see EnrollmentService::
// assertEnrollable() for the same rule enforced server-side). Likewise the
// course package list comes straight from the program, independent of
// whichever class ends up picked.
const classOptions = computed(() =>
  classes.value
    .filter((c) => c.academic_program_id === form.academic_program_id)
    .map((c) => ({ value: String(c.id), label: c.name })),
)

const packageOptions = computed(() =>
  packages.value
    .filter((pkg) => pkg.academic_program_id === form.academic_program_id)
    .map((pkg) => ({ value: String(pkg.id), label: pkg.name })),
)

const selectedPackage = computed(() => packages.value.find((pkg) => pkg.id === form.course_package_id) ?? null)

const tables = ref<{ total_tables: number; available: { id: number; name: string }[] } | null>(null)
const loadingTables = ref(false)

const tableOptions = computed(() => (tables.value?.available ?? []).map((table) => ({ value: String(table.id), label: table.name })))
const tableRequired = computed(() => (tables.value?.total_tables ?? 0) > 0)

// --- Payment panel ------------------------------------------------------

const feeTypeLabels: Record<FeeType, string> = {
  monthly: 'admin.enrollments.feeTypeMonthly',
  term: 'admin.enrollments.feeTypeTerm',
  video: 'admin.enrollments.feeTypeVideo',
  monthly_online: 'admin.enrollments.feeTypeMonthlyOnline',
  term_online: 'admin.enrollments.feeTypeTermOnline',
}
/** Priority order used to pick a fallback when the package has no `term` fee. */
const feeTypePriority: FeeType[] = ['monthly', 'term', 'video', 'monthly_online', 'term_online']

function packageFee(pkg: CoursePackage, feeType: FeeType): number | null {
  const field = `fee_${feeType}` as const
  return pkg[field]
}

// Only tiers the selected package actually has set are offered — mirrors
// CoursePackageFormModal's own "only show what's configured" pattern.
const feeTypeOptions = computed(() => {
  if (!selectedPackage.value) return []
  return feeTypePriority
    .filter((type) => packageFee(selectedPackage.value!, type) !== null)
    .map((type) => ({ value: type, label: t(feeTypeLabels[type]) }))
})

const fee = computed(() => (selectedPackage.value && form.fee_type ? (packageFee(selectedPackage.value, form.fee_type) ?? 0) : 0))
const feeToPay = computed(() => Math.max(0, fee.value - form.discount_price))
const paid = computed(() => Math.min(form.received_amount, feeToPay.value))
const debt = computed(() => Math.max(0, feeToPay.value - paid.value))
const payback = computed(() => Math.max(0, form.received_amount - feeToPay.value))

const paymentMethodOptions = computed(() => paymentMethods.map((method) => ({ value: method, label: t(`admin.payments.method${methodKey(method)}`) })))

function methodKey(method: PaymentMethodValue): string {
  return method
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

// Picking a different program invalidates whatever class/package were
// chosen for the previous one — both lists are scoped to the program.
watch(
  () => form.academic_program_id,
  () => {
    form.class_id = null
    form.course_package_id = null
    form.table_id = null
    tables.value = null
  },
)

// A new package (or losing the one that was picked) resets fee type to its
// default — prefer "term", else whichever tier the package actually has.
watch(
  () => form.course_package_id,
  () => {
    const pkg = selectedPackage.value
    if (!pkg) {
      form.fee_type = null
      return
    }
    form.fee_type = packageFee(pkg, 'term') !== null ? 'term' : (feeTypePriority.find((type) => packageFee(pkg, type) !== null) ?? null)
    form.discount_reason = ''
    form.discount_price = 0
  },
)

// Received Money defaults to the full amount owed — staff lowers it for a
// partial payment/debt, or raises it to record change owed back.
watch(feeToPay, (value) => {
  form.received_amount = value
})

function onFeeTypeChange(value: string) {
  form.fee_type = (value || null) as FeeType | null
}

async function onClassChange(value: string) {
  form.class_id = value ? Number(value) : null
  form.table_id = null
  tables.value = null

  if (form.class_id === null) return

  loadingTables.value = true
  try {
    tables.value = await classesService.availableTables(form.class_id)
  } finally {
    loadingTables.value = false
  }
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
  if (!selectedStudent.value || !form.class_id || !form.course_package_id || !form.fee_type) return
  if (tableRequired.value && form.table_id === null) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await enrollmentsService.enrollInPackage({
      student_id: selectedStudent.value.id,
      class_id: form.class_id,
      table_id: form.table_id,
      course_package_id: form.course_package_id,
      enrolled_at: form.enrolled_at,
      fee_type: form.fee_type,
      discount_reason: form.discount_reason || null,
      discount_price: form.discount_price,
      received_amount: form.received_amount,
      payment_method: form.received_amount > 0 ? form.payment_method : null,
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
  loading.value = true
  try {
    ;[programs.value, classes.value, packages.value] = await Promise.all([
      academicProgramsService.listAll(),
      classesService.listAll(),
      coursePackagesService.listAll(),
    ])
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.enrollments.createPackageTitle') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.enrollments.createPackageSubtitle') }}</p>
    </div>

    <BaseAlert v-if="generalError" variant="danger" class="mb-4">{{ generalError }}</BaseAlert>

    <form class="grid gap-8 lg:grid-cols-2" @submit.prevent="submit">
      <div class="space-y-6">
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
          :model-value="form.academic_program_id !== null ? String(form.academic_program_id) : ''"
          :options="programOptions"
          :disabled="loading"
          :placeholder="t('admin.enrollments.selectProgram')"
          :label="t('admin.enrollments.program')"
          required
          :error="errors.academic_program_id?.[0]"
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

        <BaseSelect
          :model-value="form.class_id !== null ? String(form.class_id) : ''"
          :options="classOptions"
          :disabled="!form.academic_program_id"
          :placeholder="t('admin.enrollments.selectClass')"
          :label="t('admin.enrollments.class')"
          :hint="t('admin.enrollments.packageClassHint')"
          required
          :error="errors.class_id?.[0]"
          @update:model-value="onClassChange"
        />

        <div v-if="tableRequired || loadingTables">
          <BaseSelect
            :model-value="form.table_id !== null ? String(form.table_id) : ''"
            :options="tableOptions"
            :disabled="loadingTables"
            :required="tableRequired"
            :placeholder="loadingTables ? t('common.loading') : t('admin.enrollments.selectTable')"
            :label="t('admin.enrollments.table')"
            :error="errors.table_id?.[0]"
            @update:model-value="form.table_id = $event ? Number($event) : null"
          />
          <p v-if="!loadingTables && tableOptions.length === 0" class="mt-1.5 text-sm text-danger-600">
            {{ t('admin.enrollments.noTablesAvailable') }}
          </p>
        </div>

        <BaseInput v-model="form.enrolled_at" type="date" required :label="t('admin.enrollments.enrolledAt')" :error="errors.enrolled_at?.[0]" />
      </div>

      <div class="space-y-4 rounded-[--radius-card] border border-neutral-200 bg-neutral-50 p-5">
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.enrollments.paymentSection') }}</h2>

        <template v-if="selectedPackage">
          <p v-if="selectedPackage.books?.length" class="text-xs text-neutral-500">
            {{ t('admin.enrollments.includedCourses') }}: {{ selectedPackage.books.map((b) => b.title).join(', ') }}
          </p>

          <BaseSelect
            :model-value="form.fee_type ?? ''"
            :options="feeTypeOptions"
            :placeholder="t('admin.enrollments.selectFeeType')"
            :label="t('admin.enrollments.feeType')"
            required
            :error="errors.fee_type?.[0]"
            @update:model-value="onFeeTypeChange"
          />

          <div class="flex items-center justify-between text-sm">
            <span class="text-neutral-500">{{ t('admin.enrollments.fee') }}</span>
            <span class="font-semibold text-neutral-800">{{ selectedPackage.currency }} {{ fee.toFixed(2) }}</span>
          </div>

          <LookupSelect
            v-model="form.discount_reason"
            category="DISCOUNT_REASON"
            :label="t('admin.enrollments.discountReason')"
            :error="errors.discount_reason?.[0]"
          />

          <BaseInput
            :model-value="String(form.discount_price)"
            type="number"
            step="0.01"
            :label="t('admin.enrollments.discountPrice')"
            :error="errors.discount_price?.[0]"
            @update:model-value="form.discount_price = Number($event) || 0"
          />

          <div class="flex items-center justify-between border-t border-neutral-200 pt-3 text-sm">
            <span class="font-medium text-neutral-700">{{ t('admin.enrollments.feeToPay') }}</span>
            <span class="font-semibold text-neutral-900">{{ selectedPackage.currency }} {{ feeToPay.toFixed(2) }}</span>
          </div>

          <BaseInput
            :model-value="String(form.received_amount)"
            type="number"
            step="0.01"
            :label="t('admin.enrollments.receivedMoney')"
            :error="errors.received_amount?.[0]"
            @update:model-value="form.received_amount = Number($event) || 0"
          />

          <BaseSelect
            v-if="form.received_amount > 0"
            v-model="form.payment_method"
            :options="paymentMethodOptions"
            :label="t('admin.invoices.paymentMethod')"
            :error="errors.payment_method?.[0]"
          />

          <dl class="space-y-1.5 border-t border-neutral-200 pt-3 text-sm">
            <div class="flex items-center justify-between">
              <dt class="text-neutral-500">{{ t('admin.enrollments.paid') }}</dt>
              <dd class="font-medium text-neutral-800">{{ selectedPackage.currency }} {{ paid.toFixed(2) }}</dd>
            </div>
            <div class="flex items-center justify-between">
              <dt class="text-neutral-500">{{ t('admin.enrollments.debt') }}</dt>
              <dd class="font-medium" :class="debt > 0 ? 'text-danger-600' : 'text-neutral-800'">{{ selectedPackage.currency }} {{ debt.toFixed(2) }}</dd>
            </div>
            <div class="flex items-center justify-between">
              <dt class="text-neutral-500">{{ t('admin.enrollments.payback') }}</dt>
              <dd class="font-medium text-neutral-800">{{ selectedPackage.currency }} {{ payback.toFixed(2) }}</dd>
            </div>
          </dl>

          <p class="text-xs text-neutral-500">{{ t('admin.enrollments.feeServerComputedHint') }}</p>
        </template>
        <p v-else class="text-sm text-neutral-400">{{ t('admin.enrollments.selectPackage') }}</p>
      </div>

      <div class="flex gap-3 lg:col-span-2">
        <BaseButton
          type="submit"
          :loading="submitting"
          :disabled="!selectedStudent || !form.class_id || !form.course_package_id || !form.fee_type || (tableRequired && !form.table_id)"
        >
          {{ t('common.save') }}
        </BaseButton>
        <BaseButton type="button" variant="outline" @click="router.push('/admin/enrollments')">{{ t('common.cancel') }}</BaseButton>
      </div>
    </form>
  </div>
</template>

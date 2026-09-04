<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { classesService, type SchoolClass } from '@/services/classes'
import { coursePackagesService, type CoursePackage } from '@/services/coursePackages'
import { enrollmentsService, type Enrollment, type FeeType } from '@/services/enrollments'
import { ApiRequestError } from '@/types/api'

/**
 * Change class and/or course — see EnrollmentService::transferClass(). The
 * class/room can always move; the course can only move while nothing has
 * been paid yet (enrollment.is_paid), per the user's own rule: "if paid we
 * don't allow to change course, but can change class."
 */
const props = defineProps<{
  modelValue: boolean
  enrollment: Enrollment | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const classes = ref<SchoolClass[]>([])
const packages = ref<CoursePackage[]>([])
const loadingCatalog = ref(false)

const form = reactive({
  class_id: null as number | null,
  course_package_id: null as number | null,
  table_id: null as number | null,
  fee_type: null as FeeType | null,
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const canChangeCourse = computed(() => !props.enrollment?.is_paid)

const programId = computed(() => props.enrollment?.academic_program_id ?? null)

const classOptions = computed(() =>
  classes.value.filter((c) => c.academic_program_id === programId.value).map((c) => ({ value: String(c.id), label: c.name })),
)

const packageOptions = computed(() =>
  packages.value.filter((pkg) => pkg.academic_program_id === programId.value).map((pkg) => ({ value: String(pkg.id), label: pkg.name })),
)

const selectedPackage = computed(() => packages.value.find((pkg) => pkg.id === form.course_package_id) ?? null)

const feeTypeLabels: Record<FeeType, string> = {
  monthly: 'admin.enrollments.feeTypeMonthly',
  term: 'admin.enrollments.feeTypeTerm',
  video: 'admin.enrollments.feeTypeVideo',
  monthly_online: 'admin.enrollments.feeTypeMonthlyOnline',
  term_online: 'admin.enrollments.feeTypeTermOnline',
}
const feeTypePriority: FeeType[] = ['monthly', 'term', 'video', 'monthly_online', 'term_online']

function packageFee(pkg: CoursePackage, feeType: FeeType): number | null {
  return pkg[`fee_${feeType}` as const]
}

const feeTypeOptions = computed(() => {
  if (!selectedPackage.value) return []
  return feeTypePriority
    .filter((type) => packageFee(selectedPackage.value!, type) !== null)
    .map((type) => ({ value: type, label: t(feeTypeLabels[type]) }))
})

const tables = ref<{ total_tables: number; available: { id: number; name: string }[] } | null>(null)
const loadingTables = ref(false)
const tableOptions = computed(() => (tables.value?.available ?? []).map((t) => ({ value: String(t.id), label: t.name })))
const tableRequired = computed(() => (tables.value?.total_tables ?? 0) > 0)

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

watch(
  () => [props.modelValue, props.enrollment] as const,
  async ([open, enrollment]) => {
    if (!open || !enrollment) return

    form.class_id = enrollment.class.id
    form.course_package_id = enrollment.course_package_id
    form.table_id = enrollment.table_id
    form.fee_type = enrollment.fee_type
    tables.value = null
    errors.value = {}
    generalError.value = null

    loadingCatalog.value = true
    try {
      ;[classes.value, packages.value] = await Promise.all([classesService.listAll(), coursePackagesService.listAll()])
    } finally {
      loadingCatalog.value = false
    }
  },
  { immediate: true },
)

async function submit() {
  if (!props.enrollment || !form.class_id) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  const changingCourse = canChangeCourse.value && form.course_package_id !== props.enrollment.course_package_id

  try {
    await enrollmentsService.transfer(props.enrollment.id, {
      class_id: form.class_id,
      table_id: form.table_id,
      course_package_id: changingCourse ? form.course_package_id : undefined,
      fee_type: changingCourse ? form.fee_type : undefined,
    })
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.enrollments.transferFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.enrollments.changeClass')" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="enrollment" class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseAlert v-if="enrollment.is_paid" variant="warning">{{ t('admin.enrollments.coursePaidHint') }}</BaseAlert>

      <div class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
        {{ enrollment.student.full_name }} — {{ enrollment.class.name }} — {{ enrollment.course_package?.name ?? enrollment.book?.title ?? '—' }}
      </div>

      <BaseSelect
        :model-value="form.class_id !== null ? String(form.class_id) : ''"
        :options="classOptions"
        :disabled="loadingCatalog"
        :placeholder="t('admin.enrollments.selectClass')"
        :label="t('admin.enrollments.class')"
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
      </div>

      <BaseSelect
        :model-value="form.course_package_id !== null ? String(form.course_package_id) : ''"
        :options="packageOptions"
        :disabled="loadingCatalog || !canChangeCourse"
        :hint="!canChangeCourse ? t('admin.enrollments.coursePaidHint') : undefined"
        :placeholder="t('admin.enrollments.selectPackage')"
        :label="t('admin.enrollments.package')"
        :error="errors.course_package_id?.[0]"
        @update:model-value="form.course_package_id = $event ? Number($event) : null"
      />

      <BaseSelect
        v-if="canChangeCourse && form.course_package_id !== enrollment.course_package_id && selectedPackage"
        :model-value="form.fee_type ?? ''"
        :options="feeTypeOptions"
        :placeholder="t('admin.enrollments.selectFeeType')"
        :label="t('admin.enrollments.feeType')"
        required
        :error="errors.fee_type?.[0]"
        @update:model-value="form.fee_type = ($event || null) as FeeType | null"
      />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="!form.class_id" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

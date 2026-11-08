<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseMultiSelect from '@/components/ui/BaseMultiSelect.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { discountCodesService, type DiscountCode, type DiscountCodeStudent, type DiscountType } from '@/services/discountCodes'
import { ApiRequestError } from '@/types/api'

/** Invoices > Discount codes: one personal code per student, each with its own value. */
const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [codes: DiscountCode[]] }>()

const { t } = useI18n()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const form = reactive({
  code_prefix: '',
  name: '',
  discount_type: 'percent' as DiscountType,
  default_value: '',
  max_discount: '',
  starts_on: today(),
  expires_on: '',
  notes: '',
  course_package_ids: [] as string[],
})
const rows = ref<{ student: DiscountCodeStudent; value: string }[]>([])
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const loading = ref(false)

const courseOptions = ref<{ value: string; label: string }[]>([])
const studentSearch = ref('')
const studentResults = ref<DiscountCodeStudent[]>([])
let searchTimer: ReturnType<typeof setTimeout> | undefined

const typeOptions = computed(() => [
  { value: 'percent', label: t('admin.discountCodes.type.percent') },
  { value: 'amount', label: t('admin.discountCodes.type.amount') },
])

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    Object.assign(form, {
      code_prefix: '', name: '', discount_type: 'percent', default_value: '', max_discount: '',
      starts_on: today(), expires_on: '', notes: '', course_package_ids: [],
    })
    rows.value = []
    errors.value = {}
    saveError.value = null
    studentSearch.value = ''
    studentResults.value = []
    loading.value = true
    try {
      const options = await discountCodesService.options()
      courseOptions.value = options.course_packages.map((c) => ({ value: String(c.id), label: c.name }))
    } catch (e) {
      saveError.value = e instanceof ApiRequestError ? e.message : t('admin.discountCodes.loadFailed')
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

watch(studentSearch, (value) => {
  clearTimeout(searchTimer)
  if (!value.trim()) {
    studentResults.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    try {
      studentResults.value = (await discountCodesService.options(value.trim())).students
    } catch {
      studentResults.value = []
    }
  }, 300)
})

function addStudent(student: DiscountCodeStudent) {
  if (!rows.value.some((r) => r.student.id === student.id)) rows.value = [...rows.value, { student, value: form.default_value }]
  studentSearch.value = ''
  studentResults.value = []
}

function removeRow(index: number) {
  rows.value = rows.value.filter((_, i) => i !== index)
}

/** The code each student will get (the server adds -2 if one is taken). */
function previewCode(student: DiscountCodeStudent): string {
  const tail = (student.student_code ?? '').replace(/[^A-Za-z0-9]/g, '').toUpperCase() || String(student.id)
  return `${form.code_prefix || '…'}-${tail}`
}

const numberOrNull = (value: string) => (String(value).trim() === '' ? null : Number(value))

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const saved = await discountCodesService.bulk({
      code_prefix: form.code_prefix,
      name: form.name,
      discount_type: form.discount_type,
      max_discount: form.discount_type === 'percent' ? numberOrNull(form.max_discount) : null,
      starts_on: form.starts_on,
      expires_on: form.expires_on,
      notes: form.notes || null,
      course_package_ids: form.course_package_ids.map(Number),
      rows: rows.value.map((r) => ({ student_id: r.student.id, value: numberOrNull(r.value) })),
    })
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.discountCodes.saveFailed')
  } finally {
    saving.value = false
  }
}

/** First error on a row (its student or its value). */
function rowError(index: number): string | undefined {
  return errors.value[`rows.${index}.value`]?.[0] ?? errors.value[`rows.${index}.student_id`]?.[0]
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.discountCodes.bulk.title')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <p class="text-sm text-neutral-500">{{ t('admin.discountCodes.bulk.intro') }}</p>
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput
          :model-value="form.code_prefix"
          required
          :label="t('admin.discountCodes.bulk.prefix')"
          :hint="t('admin.discountCodes.bulk.prefixHint')"
          :error="errors.code_prefix?.[0]"
          @update:model-value="form.code_prefix = $event.toUpperCase()"
        />
        <BaseInput v-model="form.name" required :label="t('admin.discountCodes.name')" :error="errors.name?.[0]" />
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseSelect :model-value="form.discount_type" :options="typeOptions" :label="t('admin.discountCodes.typeLabel')" @update:model-value="form.discount_type = $event as DiscountType" />
        <BaseInput
          v-model="form.default_value"
          type="number"
          :label="t('admin.discountCodes.bulk.defaultValue')"
          :hint="t('admin.discountCodes.bulk.defaultValueHint')"
        />
        <BaseInput
          v-if="form.discount_type === 'percent'"
          v-model="form.max_discount"
          type="number"
          :label="t('admin.discountCodes.maxDiscount')"
          :hint="t('admin.discountCodes.maxDiscountHint')"
          :error="errors.max_discount?.[0]"
        />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.starts_on" type="date" required :label="t('admin.discountCodes.startsOn')" :error="errors.starts_on?.[0]" />
        <BaseInput v-model="form.expires_on" type="date" required :label="t('admin.discountCodes.expiresOn')" :error="errors.expires_on?.[0]" />
      </div>

      <BaseMultiSelect
        v-model="form.course_package_ids"
        :options="courseOptions"
        :disabled="loading"
        :label="t('admin.discountCodes.courses')"
        :placeholder="t('admin.discountCodes.allCourses')"
        :error="errors.course_package_ids?.[0]"
      />

      <!-- Students, each with their own value -->
      <div>
        <div class="relative">
          <BaseInput v-model="studentSearch" :label="t('admin.discountCodes.students')" :placeholder="t('admin.discountCodes.studentSearch')" :error="errors.rows?.[0]" />
          <ul
            v-if="studentResults.length"
            class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-neutral-200 bg-white py-1 text-sm shadow-lg"
          >
            <li v-for="s in studentResults" :key="s.id">
              <button type="button" class="block w-full px-3 py-2 text-left hover:bg-neutral-50" @click="addStudent(s)">
                {{ s.name }} <span class="text-xs text-neutral-500">{{ s.student_code }}</span>
              </button>
            </li>
          </ul>
        </div>

        <ul v-if="rows.length" class="mt-2 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="(row, index) in rows" :key="row.student.id" class="flex flex-col gap-2 px-3 py-2 sm:flex-row sm:items-center">
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-neutral-900">{{ row.student.name }}</p>
              <p class="font-mono text-xs text-neutral-500">{{ previewCode(row.student) }}</p>
            </div>
            <div class="flex items-start gap-2">
              <BaseInput
                v-model="row.value"
                type="number"
                class="w-32"
                :placeholder="form.discount_type === 'percent' ? '%' : t('admin.discountCodes.amountValue')"
                :error="rowError(index)"
              />
              <button type="button" class="mt-2 text-sm font-medium text-danger-600 hover:text-red-700" @click="removeRow(index)">
                {{ t('admin.discountCodes.bulk.remove') }}
              </button>
            </div>
          </li>
        </ul>
        <p v-else class="mt-2 text-xs text-neutral-500">{{ t('admin.discountCodes.bulk.noStudents') }}</p>
      </div>

      <BaseInput v-model="form.notes" :label="t('admin.discountCodes.notes')" :error="errors.notes?.[0]" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="loading || rows.length === 0" @click="save">
        {{ t('admin.discountCodes.bulk.save', { count: rows.length }) }}
      </BaseButton>
    </template>
  </BaseModal>
</template>

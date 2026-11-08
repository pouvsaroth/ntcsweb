<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseMultiSelect from '@/components/ui/BaseMultiSelect.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import {
  discountCodesService,
  type DiscountAudience,
  type DiscountCode,
  type DiscountCodeStatus,
  type DiscountCodeStudent,
  type DiscountType,
} from '@/services/discountCodes'
import { ApiRequestError } from '@/types/api'

/** Invoices > Discount codes: add or edit a code, and give a personal code to students. */
const props = defineProps<{ modelValue: boolean; discountCode?: DiscountCode | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [code: DiscountCode] }>()

const { t } = useI18n()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const form = reactive({
  code: '',
  name: '',
  audience: 'public' as DiscountAudience,
  discount_type: 'percent' as DiscountType,
  value: '',
  max_discount: '',
  starts_on: today(),
  expires_on: '',
  max_uses: '',
  status: 'active' as DiscountCodeStatus,
  notes: '',
  course_package_ids: [] as string[],
})
const students = ref<DiscountCodeStudent[]>([])
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const loading = ref(false)

const courseOptions = ref<{ value: string; label: string }[]>([])
const studentSearch = ref('')
const studentResults = ref<DiscountCodeStudent[]>([])
let searchTimer: ReturnType<typeof setTimeout> | undefined

const audienceOptions = computed(() => [
  { value: 'public', label: t('admin.discountCodes.audience.public') },
  { value: 'personal', label: t('admin.discountCodes.audience.personal') },
])
const typeOptions = computed(() => [
  { value: 'percent', label: t('admin.discountCodes.type.percent') },
  { value: 'amount', label: t('admin.discountCodes.type.amount') },
])
const statusOptions = computed(() => [
  { value: 'active', label: t('admin.discountCodes.effective.active') },
  { value: 'inactive', label: t('admin.discountCodes.effective.inactive') },
])

function fill(code: DiscountCode | null) {
  form.code = code?.code ?? ''
  form.name = code?.name ?? ''
  form.audience = code?.audience ?? 'public'
  form.discount_type = code?.discount_type ?? 'percent'
  form.value = code ? String(code.value) : ''
  form.max_discount = code?.max_discount != null ? String(code.max_discount) : ''
  form.starts_on = code?.starts_on ?? today()
  form.expires_on = code?.expires_on ?? ''
  form.max_uses = code?.max_uses != null ? String(code.max_uses) : ''
  form.status = code?.status ?? 'active'
  form.notes = code?.notes ?? ''
  form.course_package_ids = (code?.course_packages ?? []).map((c) => String(c.id))
  students.value = code?.students ?? []
}

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    errors.value = {}
    saveError.value = null
    studentSearch.value = ''
    studentResults.value = []
    fill(props.discountCode ?? null)
    loading.value = true
    try {
      const [options, full] = await Promise.all([
        discountCodesService.options(),
        props.discountCode ? discountCodesService.get(props.discountCode.id) : Promise.resolve(null),
      ])
      courseOptions.value = options.course_packages.map((c) => ({ value: String(c.id), label: c.name }))
      if (full) fill(full)
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
  if (!students.value.some((s) => s.id === student.id)) students.value = [...students.value, student]
  studentSearch.value = ''
  studentResults.value = []
}

function removeStudent(id: number) {
  students.value = students.value.filter((s) => s.id !== id)
}

const numberOrNull = (value: string) => (value.trim() === '' ? null : Number(value))

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    code: form.code,
    name: form.name,
    audience: form.audience,
    discount_type: form.discount_type,
    value: numberOrNull(form.value),
    max_discount: form.discount_type === 'percent' ? numberOrNull(form.max_discount) : null,
    starts_on: form.starts_on,
    expires_on: form.expires_on,
    max_uses: numberOrNull(form.max_uses),
    status: form.status,
    notes: form.notes || null,
    course_package_ids: form.course_package_ids.map(Number),
    student_ids: form.audience === 'personal' ? students.value.map((s) => s.id) : [],
  }
  try {
    const saved = props.discountCode ? await discountCodesService.update(props.discountCode.id, input) : await discountCodesService.create(input)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.discountCodes.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="discountCode ? t('admin.discountCodes.edit') : t('admin.discountCodes.add')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput
          :model-value="form.code"
          required
          :label="t('admin.discountCodes.code')"
          :hint="t('admin.discountCodes.codeHint')"
          :error="errors.code?.[0]"
          @update:model-value="form.code = $event.toUpperCase()"
        />
        <BaseInput v-model="form.name" required :label="t('admin.discountCodes.name')" :error="errors.name?.[0]" />
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseSelect :model-value="form.discount_type" :options="typeOptions" :label="t('admin.discountCodes.typeLabel')" @update:model-value="form.discount_type = $event as DiscountType" />
        <BaseInput
          v-model="form.value"
          type="number"
          required
          :label="form.discount_type === 'percent' ? t('admin.discountCodes.percentValue') : t('admin.discountCodes.amountValue')"
          :error="errors.value?.[0]"
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

      <div class="grid gap-4 sm:grid-cols-3">
        <BaseInput v-model="form.starts_on" type="date" required :label="t('admin.discountCodes.startsOn')" :error="errors.starts_on?.[0]" />
        <BaseInput v-model="form.expires_on" type="date" required :label="t('admin.discountCodes.expiresOn')" :error="errors.expires_on?.[0]" />
        <BaseInput
          v-model="form.max_uses"
          type="number"
          :label="t('admin.discountCodes.maxUses')"
          :hint="t('admin.discountCodes.maxUsesHint')"
          :error="errors.max_uses?.[0]"
        />
      </div>

      <BaseMultiSelect
        v-model="form.course_package_ids"
        :options="courseOptions"
        :disabled="loading"
        :label="t('admin.discountCodes.courses')"
        :placeholder="t('admin.discountCodes.allCourses')"
        :error="errors.course_package_ids?.[0]"
      />

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseSelect :model-value="form.audience" :options="audienceOptions" :label="t('admin.discountCodes.audienceLabel')" @update:model-value="form.audience = $event as DiscountAudience" />
        <BaseSelect
          v-if="discountCode"
          :model-value="form.status"
          :options="statusOptions"
          :label="t('admin.discountCodes.status')"
          @update:model-value="form.status = $event as DiscountCodeStatus"
        />
      </div>
      <p class="-mt-2 text-xs text-neutral-500">{{ t(`admin.discountCodes.audienceHint.${form.audience}`) }}</p>

      <div v-if="form.audience === 'personal'">
        <div class="relative">
          <BaseInput v-model="studentSearch" :label="t('admin.discountCodes.students')" :placeholder="t('admin.discountCodes.studentSearch')" :error="errors.student_ids?.[0]" />
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
        <div v-if="students.length" class="mt-2 flex flex-wrap gap-2">
          <span v-for="s in students" :key="s.id" class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700">
            {{ s.name }}<template v-if="s.student_code"> · {{ s.student_code }}</template>
            <button type="button" class="ml-1 text-primary-500 hover:text-primary-800" :aria-label="t('admin.discountCodes.removeStudent')" @click="removeStudent(s.id)">×</button>
          </span>
        </div>
      </div>

      <BaseInput v-model="form.notes" :label="t('admin.discountCodes.notes')" :error="errors.notes?.[0]" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="loading" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { discountCodesService, type DiscountCode, type DiscountCodeStudent } from '@/services/discountCodes'
import { ApiRequestError } from '@/types/api'

/**
 * Invoices > Discount codes > Assign discount to student: each student gets
 * their own code made from the chosen code, e.g. NTS000543_D01_101126.
 * Students who already have it keep their code. After saving, the new codes
 * are shown.
 */
const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const codes = ref<DiscountCode[]>([])
const codeId = ref('')
const students = ref<DiscountCodeStudent[]>([])
const studentSearch = ref('')
const studentResults = ref<DiscountCodeStudent[]>([])
const loading = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
/** The codes just made (null = not saved yet). */
const made = ref<string[] | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | undefined

/** Codes that can still be used (not expired, not inactive). */
const codeOptions = computed(() =>
  codes.value
    .filter((c) => c.effective_status !== 'expired' && c.effective_status !== 'inactive')
    .map((c) => ({ value: String(c.id), label: `${c.code} · ${c.name} (${t('admin.discountCodes.studentCount', { count: c.students_count ?? 0 })})` })),
)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    codeId.value = ''
    students.value = []
    studentSearch.value = ''
    studentResults.value = []
    errors.value = {}
    saveError.value = null
    made.value = null
    loading.value = true
    try {
      codes.value = (await discountCodesService.list({ page: 1, per_page: 100, filter: { status: 'active' }, sort: '-created_at' })).data
    } catch {
      codes.value = []
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

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    made.value = (await discountCodesService.assign(Number(codeId.value), students.value.map((s) => s.id))).made
    emit('saved')
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    saveError.value = e instanceof ApiRequestError ? e.message : t('admin.discountCodes.assign.failed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.discountCodes.assign.title')" @update:model-value="emit('update:modelValue', $event)">
    <div v-if="made" class="space-y-3">
      <template v-if="made.length">
        <p class="text-sm font-medium text-green-700">{{ t('admin.discountCodes.assign.done') }}</p>
        <ul class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="code in made" :key="code" class="px-3 py-2 font-mono text-sm text-neutral-900">{{ code }}</li>
        </ul>
      </template>
      <p v-else class="text-sm text-neutral-600">{{ t('admin.discountCodes.assign.noneNew') }}</p>
    </div>
    <form v-else class="space-y-4" @submit.prevent="save">
      <p class="text-sm text-neutral-600">{{ t('admin.discountCodes.assign.intro') }}</p>
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>

      <BaseSelect
        v-model="codeId"
        :options="codeOptions"
        :placeholder="loading ? t('common.loading') : t('admin.discountCodes.assign.chooseCode')"
        required
        :label="t('admin.discountCodes.code')"
        :hint="!loading && codeOptions.length === 0 ? t('admin.discountCodes.assign.noCodes') : undefined"
        :error="errors.discount_code_id?.[0]"
      />

      <div>
        <div class="relative">
          <BaseInput v-model="studentSearch" :label="t('admin.discountCodes.students')" :placeholder="t('admin.discountCodes.studentSearch')" :error="errors.student_ids?.[0]" />
          <ul v-if="studentResults.length" class="mt-1 max-h-56 w-full overflow-auto rounded-lg border border-neutral-200 bg-white py-1 text-sm shadow-lg">
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
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton v-if="!made" :loading="saving" :disabled="!codeId || students.length === 0" @click="save">
        {{ t('admin.discountCodes.assign.save', { count: students.length }) }}
      </BaseButton>
    </template>
  </BaseModal>
</template>

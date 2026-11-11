<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { discountCodesService, type DiscountCode, type DiscountCodeAssignee, type DiscountCodeStatus, type DiscountType } from '@/services/discountCodes'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Invoices > Discount codes: add or edit a code. The expiry date is
 * optional (empty = no end). Editing also lists the students given it, each
 * with their own code, and can take it back from one.
 */
const props = defineProps<{ modelValue: boolean; discountCode?: DiscountCode | null; canUpdate?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [code: DiscountCode] }>()

const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const form = reactive({
  code: '',
  name: '',
  discount_type: 'percent' as DiscountType,
  value: '',
  max_discount: '',
  starts_on: today(),
  expires_on: '',
  status: 'active' as DiscountCodeStatus,
  notes: '',
})
const students = ref<DiscountCodeAssignee[]>([])
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const loading = ref(false)

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
  form.discount_type = code?.discount_type ?? 'percent'
  form.value = code ? String(code.value) : ''
  form.max_discount = code?.max_discount != null ? String(code.max_discount) : ''
  form.starts_on = code?.starts_on ?? today()
  form.expires_on = code?.expires_on ?? ''
  form.status = code?.status ?? 'active'
  form.notes = code?.notes ?? ''
  students.value = code?.students ?? []
}

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    errors.value = {}
    saveError.value = null
    fill(props.discountCode ?? null)
    if (!props.discountCode) return
    loading.value = true
    try {
      fill(await discountCodesService.get(props.discountCode.id))
    } catch (e) {
      saveError.value = e instanceof ApiRequestError ? e.message : t('admin.discountCodes.loadFailed')
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

const numberOrNull = (value: string) => (value.trim() === '' ? null : Number(value))

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    code: form.code,
    name: form.name,
    discount_type: form.discount_type,
    value: numberOrNull(form.value),
    max_discount: form.discount_type === 'percent' ? numberOrNull(form.max_discount) : null,
    starts_on: form.starts_on,
    expires_on: form.expires_on || null,
    status: form.status,
    notes: form.notes || null,
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

/** Change one student's own expiry (empty = no end date); saved straight away. */
async function setExpiry(student: DiscountCodeAssignee, value: string) {
  if (!props.discountCode || (value || null) === student.expires_on) return
  saveError.value = null
  try {
    const updated = await discountCodesService.setStudentExpiry(props.discountCode.id, student.id, value || null)
    students.value = updated.students ?? []
    emit('saved', updated)
  } catch (e) {
    saveError.value = e instanceof ApiRequestError ? e.message : t('admin.discountCodes.expiryFailed')
  }
}

async function takeBack(student: DiscountCodeAssignee) {
  if (!props.discountCode) return
  if (!(await confirmDialog.confirm({ message: t('admin.discountCodes.removeStudentConfirm', { name: student.name }), danger: true }))) return
  saveError.value = null
  try {
    const updated = await discountCodesService.unassign(props.discountCode.id, student.id)
    students.value = updated.students ?? []
    emit('saved', updated)
  } catch (e) {
    saveError.value = e instanceof ApiRequestError ? e.message : t('admin.discountCodes.removeStudentFailed')
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

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.starts_on" type="date" required :label="t('admin.discountCodes.startsOn')" :error="errors.starts_on?.[0]" />
        <BaseInput
          v-model="form.expires_on"
          type="date"
          :label="t('admin.discountCodes.expiresOn')"
          :hint="t('admin.discountCodes.expiresHint')"
          :error="errors.expires_on?.[0]"
        />
      </div>

      <BaseSelect
        v-if="discountCode"
        :model-value="form.status"
        :options="statusOptions"
        :label="t('admin.discountCodes.status')"
        @update:model-value="form.status = $event as DiscountCodeStatus"
      />

      <BaseInput v-model="form.notes" :label="t('admin.discountCodes.notes')" :error="errors.notes?.[0]" />

      <!-- Editing: the students given this code, each with their own code. -->
      <div v-if="discountCode" class="border-t border-neutral-200 pt-4">
        <p class="mb-2 text-sm font-medium text-neutral-700">{{ t('admin.discountCodes.studentsWithCode') }}</p>
        <p v-if="!loading && students.length === 0" class="text-sm text-neutral-500">{{ t('admin.discountCodes.studentsNone') }}</p>
        <ul v-else class="max-h-64 divide-y divide-neutral-100 overflow-auto rounded-lg border border-neutral-200">
          <li v-for="s in students" :key="s.id" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 px-3 py-2 text-sm">
            <div class="min-w-0 flex-1">
              <p class="truncate text-neutral-900">{{ s.name }} <span v-if="s.student_code" class="text-xs text-neutral-500">· {{ s.student_code }}</span></p>
              <p class="truncate font-mono text-xs text-neutral-600">
                {{ s.code }}<template v-if="s.assigned_on"> · {{ formatDate(s.assigned_on) }}</template>
                <template v-if="s.uses_count"> · {{ t('admin.discountCodes.uses') }}: {{ s.uses_count }}</template>
              </p>
            </div>
            <div class="flex items-center gap-2">
              <span v-if="s.expired" class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{{ t('admin.discountCodes.expired') }}</span>
              <div v-if="canUpdate" class="w-40">
                <DateInput
                  :model-value="s.expires_on ?? ''"
                  :min="discountCode?.starts_on"
                  :title="t('admin.discountCodes.studentExpiry')"
                  @update:model-value="setExpiry(s, $event)"
                />
              </div>
              <span v-else class="text-xs text-neutral-600">
                {{ t('admin.discountCodes.studentExpiry') }}: {{ s.expires_on ? formatDate(s.expires_on) : t('admin.discountCodes.noEnd') }}
              </span>
            </div>
            <button v-if="canUpdate" type="button" class="shrink-0 text-sm font-medium text-danger-600 hover:text-red-700" @click="takeBack(s)">
              {{ t('admin.discountCodes.removeStudent') }}
            </button>
          </li>
        </ul>
      </div>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="loading" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

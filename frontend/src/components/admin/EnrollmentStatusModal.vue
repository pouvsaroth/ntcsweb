<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import {
  enrollmentsService,
  enrollmentStatusesManageable,
  enrollmentStatusesRequiringReason,
  type Enrollment,
  type EnrollmentStatus,
} from '@/services/enrollments'
import { invoicesService, type Invoice } from '@/services/invoices'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

const props = defineProps<{
  modelValue: boolean
  enrollment: Enrollment | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()
const auth = useAuthStore()

function statusKey(status: EnrollmentStatus): string {
  return status
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const statusOptions = enrollmentStatusesManageable.map((status) => ({ value: status, label: t(`admin.enrollments.status${statusKey(status)}`) }))

const form = reactive({
  status: 'active' as EnrollmentStatus,
  reason: '',
  effective_date: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
/**
 * Set when a student returns to Studying but their old table went to someone
 * else meanwhile — the server clears it (see EnrollmentService::changeStatus()),
 * so the dialog stays open to say so instead of closing silently.
 */
const clearedTableName = ref<string | null>(null)

const reasonRequired = computed(() => enrollmentStatusesRequiringReason.includes(form.status))

// --- Unpaid invoices ---------------------------------------------------------
// Changing the status never touches the student's invoices (see
// EnrollmentService::changeStatus()), so when staff stop/suspend/mark a
// student abandoned, any money still owed is shown right here — otherwise the
// debt is easy to forget once the student leaves the Studying lists.
// Needs invoices.view; without it the section just doesn't appear.

const canViewInvoices = computed(() => auth.can('invoices.view'))
const unpaidInvoices = ref<Invoice[]>([])
const unpaidLoading = ref(false)

/** Owed per currency — a student can have both USD and KHR invoices. */
const unpaidTotals = computed(() => {
  const totals = new Map<Invoice['currency'], number>()
  for (const invoice of unpaidInvoices.value) totals.set(invoice.currency, (totals.get(invoice.currency) ?? 0) + invoice.balance)
  return [...totals].map(([currency, amount]) => formatMoney(amount, currency)).join(' + ')
})

async function loadUnpaidInvoices(studentId: number) {
  unpaidInvoices.value = []
  if (!canViewInvoices.value) return

  unpaidLoading.value = true
  try {
    const result = await invoicesService.list({
      page: 1,
      per_page: 50,
      sort: 'invoice_date',
      filter: { student_id: String(studentId), status: 'ISSUED,PARTIALLY_PAID,OVERDUE' },
    })
    unpaidInvoices.value = result.data
  } catch {
    // Not worth blocking the status change over — the section stays empty.
  } finally {
    unpaidLoading.value = false
  }
}

watch(
  () => [props.modelValue, props.enrollment] as const,
  ([open, enrollment]) => {
    if (!open || !enrollment) return

    form.status = enrollment.status === 'dropped' ? 'active' : enrollment.status
    // Blank on every open — a new status change is a fresh reason, not an
    // edit of the last one (the full history is in the status-history modal).
    form.reason = ''
    form.effective_date = ''
    errors.value = {}
    generalError.value = null
    clearedTableName.value = null
    void loadUnpaidInvoices(enrollment.student.id)
  },
  { immediate: true },
)

async function submit() {
  if (!props.enrollment) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const updated = await enrollmentsService.changeStatus(props.enrollment.id, {
      status: form.status,
      reason: reasonRequired.value ? form.reason : null,
      effective_date: reasonRequired.value ? form.effective_date : null,
    })
    emit('saved')

    if (props.enrollment.table_id !== null && updated.table_id === null) {
      clearedTableName.value = props.enrollment.table?.name ?? ''
      return
    }
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.enrollments.statusSaveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.enrollments.changeStatus')" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="enrollment" class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseAlert v-if="clearedTableName !== null" variant="warning">
        {{ t('admin.enrollments.tableClearedOnReturn', { table: clearedTableName }) }}
      </BaseAlert>

      <div class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
        {{ enrollment.student.full_name }} — {{ enrollment.class.name }} — {{ enrollment.course_package?.name ?? '—' }}
      </div>

      <BaseSelect v-model="form.status" :options="statusOptions" :label="t('admin.enrollments.status')" :error="errors.status?.[0]" />

      <template v-if="reasonRequired">
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">
            {{ t('admin.enrollments.statusReason') }} <span class="text-danger-600">*</span>
          </label>
          <textarea
            v-model="form.reason"
            rows="3"
            required
            class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm transition-colors placeholder:text-neutral-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          />
          <p v-if="errors.reason?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.reason[0] }}</p>
        </div>

        <BaseInput
          v-model="form.effective_date"
          type="date"
          required
          :label="t('admin.enrollments.statusEffectiveDate')"
          :error="errors.effective_date?.[0]"
        />

        <template v-if="canViewInvoices && !unpaidLoading">
          <BaseAlert v-if="unpaidInvoices.length > 0" variant="warning">
            <p class="font-semibold">
              {{ t('admin.enrollments.unpaidInvoicesTitle', { count: unpaidInvoices.length, total: unpaidTotals }) }}
            </p>
            <ul class="mt-2 space-y-1">
              <li v-for="invoice in unpaidInvoices" :key="invoice.id" class="flex items-center justify-between gap-3">
                <a :href="`/admin/invoices/${invoice.id}`" target="_blank" rel="noopener" class="font-medium text-primary-700 hover:underline">
                  {{ invoice.invoice_number }}
                </a>
                <span class="text-neutral-500">{{ formatDate(invoice.invoice_date) }}</span>
                <span class="font-medium text-danger-600">{{ formatMoney(invoice.balance, invoice.currency) }}</span>
              </li>
            </ul>
            <p class="mt-2 text-xs">{{ t('admin.enrollments.unpaidInvoicesHint') }}</p>
          </BaseAlert>
          <p v-else class="text-sm text-neutral-500">{{ t('admin.enrollments.noUnpaidInvoices') }}</p>
        </template>
      </template>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton v-if="clearedTableName === null" :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import ConfirmReasonModal from '@/components/admin/ConfirmReasonModal.vue'
import PayExpenseModal from '@/components/admin/PayExpenseModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { expensesService, type Expense, type ExpenseStatus } from '@/services/expenses'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const expenseId = computed(() => Number(route.params.id))

const expense = ref<Expense | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)

function statusKey(status: ExpenseStatus): string {
  return status
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const statusVariant: Record<ExpenseStatus, 'neutral' | 'warning' | 'success' | 'danger' | 'primary'> = {
  DRAFT: 'neutral',
  PENDING_APPROVAL: 'warning',
  APPROVED: 'primary',
  PAID: 'success',
  REJECTED: 'danger',
  CANCELLED: 'neutral',
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—'
}

async function load() {
  loading.value = true
  loadError.value = null

  try {
    expense.value = await expensesService.get(expenseId.value)
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.expenses.loadFailed')
  } finally {
    loading.value = false
  }
}

// --- Approve ---------------------------------------------------------------

async function approve() {
  if (!expense.value || !window.confirm(t('admin.expenses.approveConfirm'))) return
  actionError.value = null

  try {
    expense.value = await expensesService.approve(expense.value.id)
  } catch (error) {
    actionError.value = error instanceof ApiRequestError ? error.message : t('admin.expenses.actionFailed')
  }
}

// --- Reject / Cancel (shared reason modal) ---------------------------------

const reasonModalOpen = ref(false)
const reasonModalMode = ref<'reject' | 'cancel'>('reject')
const reasonSubmitting = ref(false)
const reasonError = ref<string | null>(null)

function openReject() {
  reasonModalMode.value = 'reject'
  reasonError.value = null
  reasonModalOpen.value = true
}

function openCancel() {
  reasonModalMode.value = 'cancel'
  reasonError.value = null
  reasonModalOpen.value = true
}

async function confirmReason(reason: string) {
  if (!expense.value) return
  reasonSubmitting.value = true
  reasonError.value = null

  try {
    expense.value =
      reasonModalMode.value === 'reject'
        ? await expensesService.reject(expense.value.id, reason)
        : await expensesService.cancel(expense.value.id, reason)
    reasonModalOpen.value = false
  } catch (error) {
    reasonError.value = error instanceof ApiRequestError ? error.message : t('admin.expenses.actionFailed')
  } finally {
    reasonSubmitting.value = false
  }
}

// --- Pay ---------------------------------------------------------------

const payModalOpen = ref(false)
const paySubmitting = ref(false)
const payError = ref<string | null>(null)

async function confirmPay(cashAccountId: number, paidDate: string) {
  if (!expense.value) return
  paySubmitting.value = true
  payError.value = null

  try {
    expense.value = await expensesService.pay(expense.value.id, cashAccountId, paidDate)
    payModalOpen.value = false
  } catch (error) {
    payError.value = error instanceof ApiRequestError ? error.message : t('admin.expenses.actionFailed')
  } finally {
    paySubmitting.value = false
  }
}

// --- Attachments -------------------------------------------------------

const uploading = ref(false)
const uploadError = ref<string | null>(null)

async function onFileSelected(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file || !expense.value) return

  uploading.value = true
  uploadError.value = null

  try {
    await expensesService.uploadAttachment(expense.value.id, file)
    await load()
  } catch (error) {
    uploadError.value = error instanceof ApiRequestError ? error.message : t('admin.expenses.actionFailed')
  } finally {
    uploading.value = false
    ;(event.target as HTMLInputElement).value = ''
  }
}

async function removeAttachment(attachmentId: number) {
  if (!expense.value || !window.confirm(t('admin.expenses.removeAttachmentConfirm'))) return

  await expensesService.removeAttachment(expense.value.id, attachmentId)
  await load()
}

onMounted(load)
</script>

<template>
  <div>
    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <template v-else-if="expense">
      <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold text-neutral-900">{{ expense.expense_number }}</h1>
            <BaseBadge :variant="statusVariant[expense.status]">{{ t(`admin.expenses.status${statusKey(expense.status)}`) }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm text-neutral-500">{{ expense.account.code }} — {{ expense.account.name }}</p>
        </div>
        <BaseButton variant="ghost" @click="router.push('/admin/expenses')">{{ t('common.change') }}</BaseButton>
      </div>

      <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

      <div class="mb-6 flex flex-wrap gap-3">
        <BaseButton v-if="expense.status === 'PENDING_APPROVAL'" @click="approve">{{ t('admin.expenses.approve') }}</BaseButton>
        <BaseButton v-if="expense.status === 'PENDING_APPROVAL'" variant="danger" @click="openReject">{{ t('admin.expenses.reject') }}</BaseButton>
        <BaseButton v-if="expense.status === 'APPROVED'" @click="payModalOpen = true">{{ t('admin.expenses.pay') }}</BaseButton>
        <BaseButton
          v-if="!['PAID', 'REJECTED', 'CANCELLED'].includes(expense.status)"
          variant="outline"
          @click="openCancel"
        >
          {{ t('admin.expenses.cancel') }}
        </BaseButton>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
          <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.expenses.detailsTitle') }}</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div><dt class="text-neutral-500">{{ t('admin.expenses.expenseDate') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(expense.expense_date) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.expenses.amount') }}</dt><dd class="font-medium text-neutral-900">${{ expense.amount.toFixed(2) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.expenses.vendor') }}</dt><dd class="font-medium text-neutral-900">{{ expense.vendor ?? '—' }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.expenses.referenceNumber') }}</dt><dd class="font-medium text-neutral-900">{{ expense.reference_number ?? '—' }}</dd></div>
              <div class="col-span-2"><dt class="text-neutral-500">{{ t('admin.expenses.description') }}</dt><dd class="font-medium text-neutral-900">{{ expense.description ?? '—' }}</dd></div>
              <div v-if="expense.cash_account"><dt class="text-neutral-500">{{ t('admin.expenses.cashAccount') }}</dt><dd class="font-medium text-neutral-900">{{ expense.cash_account.code }} — {{ expense.cash_account.name }}</dd></div>
              <div v-if="expense.rejected_reason"><dt class="text-neutral-500">{{ t('admin.expenses.reject') }}</dt><dd class="font-medium text-danger-600">{{ expense.rejected_reason }}</dd></div>
              <div v-if="expense.cancellation_reason"><dt class="text-neutral-500">{{ t('admin.expenses.cancel') }}</dt><dd class="font-medium text-danger-600">{{ expense.cancellation_reason }}</dd></div>
            </dl>
          </section>

          <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.expenses.attachmentsTitle') }}</h2>
            <BaseAlert v-if="uploadError" variant="danger" class="mb-3">{{ uploadError }}</BaseAlert>

            <ul v-if="expense.attachments.length > 0" class="mb-3 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
              <li v-for="attachment in expense.attachments" :key="attachment.id" class="flex items-center justify-between px-3 py-2 text-sm">
                <a :href="attachment.url" target="_blank" rel="noopener noreferrer" class="text-primary-700 hover:underline">{{ attachment.file_name }}</a>
                <button type="button" class="text-danger-600 hover:text-red-700" @click="removeAttachment(attachment.id)">{{ t('common.remove') }}</button>
              </li>
            </ul>
            <p v-else class="mb-3 text-sm text-neutral-400">{{ t('admin.expenses.noAttachments') }}</p>

            <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-primary-700">
              <input type="file" class="hidden" :disabled="uploading" accept=".pdf,.jpg,.jpeg,.png,.webp" @change="onFileSelected" />
              {{ uploading ? t('common.loading') : t('admin.expenses.addAttachment') }}
            </label>
          </section>
        </div>

        <div class="space-y-6">
          <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 text-sm">
            <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.expenses.historyTitle') }}</h2>
            <dl class="space-y-2">
              <div class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.expenses.createdBy') }}</dt><dd class="font-medium text-neutral-900">{{ expense.created_by ?? '—' }}</dd></div>
              <div v-if="expense.approved_by" class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.expenses.approvedBy') }}</dt><dd class="font-medium text-neutral-900">{{ expense.approved_by }}</dd></div>
              <div v-if="expense.paid_at" class="flex justify-between"><dt class="text-neutral-500">{{ t('admin.expenses.paidDate') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(expense.paid_at) }}</dd></div>
            </dl>
          </section>
        </div>
      </div>
    </template>

    <ConfirmReasonModal
      v-model="reasonModalOpen"
      :title="reasonModalMode === 'reject' ? t('admin.expenses.reject') : t('admin.expenses.cancel')"
      :label="t('admin.expenses.reasonLabel')"
      :confirm-label="reasonModalMode === 'reject' ? t('admin.expenses.reject') : t('admin.expenses.cancel')"
      danger
      :submitting="reasonSubmitting"
      :error="reasonError"
      @confirm="confirmReason"
    />

    <PayExpenseModal v-model="payModalOpen" :submitting="paySubmitting" :error="payError" @confirm="confirmPay" />
  </div>
</template>

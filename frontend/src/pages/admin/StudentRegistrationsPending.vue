<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ConfirmReasonModal from '@/components/admin/ConfirmReasonModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { studentRegistrationsService, type StudentRegistration } from '@/services/studentRegistrations'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<StudentRegistration>((query) =>
  studentRegistrationsService.list(query),
)

const columns = [
  { key: 'photo_url', label: t('admin.studentRegistrations.columnPhoto') },
  { key: 'full_name', label: t('admin.studentRegistrations.columnName') },
  { key: 'phone', label: t('admin.studentRegistrations.columnPhone') },
  { key: 'enrollment', label: t('admin.studentRegistrations.columnProgram') },
  { key: 'invoice', label: t('admin.studentRegistrations.columnFee') },
  { key: 'created_at', label: t('admin.studentRegistrations.columnSubmitted') },
  { key: 'actions', label: t('admin.studentRegistrations.columnActions'), align: 'text-right' },
]

const detail = ref<StudentRegistration | null>(null)
const actionError = ref<string | null>(null)
const approving = ref(false)

const rejectTarget = ref<StudentRegistration | null>(null)
const rejectOpen = ref(false)
const rejectSubmitting = ref(false)
const rejectError = ref<string | null>(null)

function openDetail(row: StudentRegistration) {
  detail.value = row
  actionError.value = null
}

function paymentMethodLabel(method: string | null | undefined): string {
  return method === 'QR' ? t('admin.studentRegistrations.paymentQr') : t('admin.studentRegistrations.paymentCash')
}

function openReject(row: StudentRegistration) {
  rejectTarget.value = row
  rejectError.value = null
  rejectOpen.value = true
}

async function approve(row: StudentRegistration) {
  if (!window.confirm(t('admin.studentRegistrations.approveConfirm', { name: row.full_name }))) return

  approving.value = true
  actionError.value = null

  try {
    await studentRegistrationsService.approve(row.id)
    detail.value = null
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.studentRegistrations.actionFailed')
  } finally {
    approving.value = false
  }
}

async function confirmReject(reason: string) {
  if (!rejectTarget.value) return

  rejectSubmitting.value = true
  rejectError.value = null

  try {
    await studentRegistrationsService.reject(rejectTarget.value.id, reason)
    rejectOpen.value = false
    detail.value = null
    await fetch()
  } catch (e) {
    rejectError.value = e instanceof ApiRequestError ? e.message : t('admin.studentRegistrations.actionFailed')
  } finally {
    rejectSubmitting.value = false
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.studentRegistrations.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.studentRegistrations.pageSubtitle') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <EmptyState
      v-if="!loading && items.length === 0"
      :title="t('admin.studentRegistrations.emptyTitle')"
      :message="t('admin.studentRegistrations.emptyMessage')"
    />

    <DataTable
      v-else
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :empty-message="t('admin.studentRegistrations.emptyMessage')"
    >
      <template #cell-photo_url="{ row }">
        <div class="h-10 w-10 overflow-hidden rounded-full bg-neutral-100">
          <img v-if="row.photo_url" :src="row.photo_url" alt="" class="h-full w-full object-cover" />
        </div>
      </template>
      <template #cell-full_name="{ row }">
        <button type="button" class="font-medium text-primary-700 hover:underline" @click="openDetail(row)">{{ row.full_name }}</button>
        <p class="text-xs text-neutral-500">{{ row.student_code }}</p>
      </template>
      <template #cell-enrollment="{ row }">
        <template v-if="row.enrollment">
          <p class="text-neutral-800">{{ row.enrollment.course_package?.name ?? '—' }}</p>
          <p class="text-xs text-neutral-500">{{ row.enrollment.class?.name ?? '—' }}</p>
        </template>
        <span v-else>—</span>
      </template>
      <template #cell-invoice="{ row }">
        <template v-if="row.invoice">
          <p class="text-neutral-800">{{ row.invoice.currency }} {{ row.invoice.balance.toFixed(2) }}</p>
          <BaseBadge :variant="row.invoice.intended_payment_method === 'QR' ? 'primary' : 'neutral'" class="mt-0.5">
            {{ paymentMethodLabel(row.invoice.intended_payment_method) }}
          </BaseBadge>
        </template>
        <span v-else>—</span>
      </template>
      <template #cell-created_at="{ row }">{{ new Date(row.created_at).toLocaleDateString() }}</template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <BaseButton size="sm" :loading="approving" @click="approve(row)">{{ t('admin.studentRegistrations.approve') }}</BaseButton>
          <BaseButton size="sm" variant="danger" @click="openReject(row)">{{ t('admin.studentRegistrations.reject') }}</BaseButton>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal :model-value="detail !== null" :title="detail?.full_name" size="lg" @update:model-value="detail = null">
      <template v-if="detail">
        <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

        <div class="flex gap-4">
          <div class="h-24 w-24 shrink-0 overflow-hidden rounded-lg bg-neutral-100">
            <img v-if="detail.photo_url" :src="detail.photo_url" alt="" class="h-full w-full object-cover" />
          </div>
          <dl class="grid flex-1 grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <div><dt class="text-neutral-500">{{ t('admin.studentRegistrations.columnPhone') }}</dt><dd class="font-medium text-neutral-900">{{ detail.phone }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.studentRegistrations.email') }}</dt><dd class="font-medium text-neutral-900">{{ detail.email ?? '—' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.studentRegistrations.gender') }}</dt><dd class="font-medium text-neutral-900">{{ detail.gender ?? '—' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.studentRegistrations.dateOfBirth') }}</dt><dd class="font-medium text-neutral-900">{{ detail.date_of_birth ?? '—' }}</dd></div>
            <div class="col-span-2"><dt class="text-neutral-500">{{ t('admin.studentRegistrations.address') }}</dt><dd class="font-medium text-neutral-900">{{ [detail.house_no, detail.street_no, detail.other_address].filter(Boolean).join(', ') || '—' }}</dd></div>
          </dl>
        </div>

        <div v-if="detail.enrollment" class="mt-4 rounded-lg border border-neutral-200 p-3 text-sm">
          <p class="font-medium text-neutral-900">{{ detail.enrollment.course_package?.name }}</p>
          <p class="text-neutral-500">{{ detail.enrollment.academic_program?.name }} — {{ detail.enrollment.class?.name }}</p>
          <p class="mt-1 text-neutral-700">{{ t('admin.studentRegistrations.feeType') }}: {{ detail.enrollment.fee_type }} — {{ detail.invoice?.currency }} {{ detail.enrollment.fee.toFixed(2) }}</p>
        </div>

        <div v-if="detail.invoice" class="mt-3 flex items-center justify-between rounded-lg bg-warning-50 px-3 py-2 text-sm">
          <div>
            <span class="text-neutral-700">{{ t('admin.studentRegistrations.balanceDue') }}</span>
            <BaseBadge :variant="detail.invoice.intended_payment_method === 'QR' ? 'primary' : 'neutral'" class="ml-2">
              {{ paymentMethodLabel(detail.invoice.intended_payment_method) }}
            </BaseBadge>
          </div>
          <span class="font-semibold text-neutral-900">{{ detail.invoice.currency }} {{ detail.invoice.balance.toFixed(2) }}</span>
        </div>
        <p v-if="detail.invoice?.intended_payment_method === 'QR'" class="mt-1.5 text-xs text-neutral-500">
          {{ t('admin.studentRegistrations.qrVerifyHint') }}
        </p>
      </template>

      <template #footer>
        <BaseButton variant="outline" @click="detail = null">{{ t('common.close') }}</BaseButton>
        <BaseButton v-if="detail" variant="danger" @click="openReject(detail)">{{ t('admin.studentRegistrations.reject') }}</BaseButton>
        <BaseButton v-if="detail" :loading="approving" @click="approve(detail)">{{ t('admin.studentRegistrations.approve') }}</BaseButton>
      </template>
    </BaseModal>

    <ConfirmReasonModal
      v-model="rejectOpen"
      :title="t('admin.studentRegistrations.reject')"
      :label="t('admin.studentRegistrations.reasonLabel')"
      :confirm-label="t('admin.studentRegistrations.reject')"
      danger
      :submitting="rejectSubmitting"
      :error="rejectError"
      @confirm="confirmReject"
    />
  </div>
</template>

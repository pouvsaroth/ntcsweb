<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import GradebookPicker from '@/components/admin/GradebookPicker.vue'
import ConfirmReasonModal from '@/components/admin/ConfirmReasonModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { resultApprovalsService, type GradebookPick, type ResultSheetRow, type SheetStatus } from '@/services/gradebook'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

/**
 * Examination > Result Approval (docs/academics/gradebook.md, 7c): every
 * class + subject's results for a term and where they are — draft,
 * waiting, approved, returned. Teachers submit; approvers (or the Approval
 * Flow group whose turn it is) approve or return. The same items are in
 * eApprovals → Approvals.
 */
const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

const pick = ref<GradebookPick | null>(null)
const rows = ref<ResultSheetRow[]>([])
// Spinner until the picker has chosen a term and the first list is in.
const loading = ref(true)
const error = ref<string | null>(null)
const busy = ref<number | null>(null)
const status = ref<'' | SheetStatus>('')

const STATUSES: SheetStatus[] = ['pending', 'approved', 'returned', 'draft']
const variant: Record<SheetStatus, 'neutral' | 'primary' | 'success' | 'warning'> = { draft: 'neutral', pending: 'primary', approved: 'success', returned: 'warning' }

async function load() {
  const p = pick.value
  if (!p?.termId) {
    loading.value = false
    return
  }
  loading.value = true
  error.value = null
  try {
    rows.value = await resultApprovalsService.sheets({ academic_term_id: p.termId, class_id: p.classId ?? undefined })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.loadFailed')
  } finally {
    loading.value = false
  }
}

function onPick(p: GradebookPick) {
  pick.value = p
  void load()
}

const shown = computed(() => (status.value ? rows.value.filter((r) => r.status === status.value) : rows.value))
const count = (s: SheetStatus) => rows.value.filter((r) => r.status === s).length

const entryLink = (r: ResultSheetRow) => ({
  path: '/admin/examination/entry',
  query: { year: pick.value?.yearId ?? undefined, term: r.term?.id, class: r.class?.id, subject: r.subject?.id },
})

async function act(r: ResultSheetRow, action: 'submit' | 'approve') {
  const msg = action === 'submit' ? t('gradebook.approval.submitConfirm') : t('gradebook.approval.approveConfirm', { what: `${r.class?.name} · ${r.subject?.title}` })
  if (!(await confirmDialog.confirm(msg))) return
  busy.value = r.id
  error.value = null
  try {
    if (action === 'submit') await resultApprovalsService.submit({ class_id: r.class!.id, book_id: r.subject!.id, academic_term_id: r.term!.id })
    else await resultApprovalsService.approve(r.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.fieldError('sheet') ?? e.fieldError('status') ?? e.message) : t('gradebook.saveFailed')
  } finally {
    busy.value = null
  }
}

const returning = ref<ResultSheetRow | null>(null)
const returnOpen = ref(false)
const returnBusy = ref(false)
const returnError = ref<string | null>(null)
function openReturn(r: ResultSheetRow) {
  returning.value = r
  returnError.value = null
  returnOpen.value = true
}
async function confirmReturn(reason: string) {
  if (!returning.value) return
  returnBusy.value = true
  try {
    await resultApprovalsService.return(returning.value.id, reason)
    returnOpen.value = false
    await load()
  } catch (e) {
    returnError.value = e instanceof ApiRequestError ? e.message : t('gradebook.saveFailed')
  } finally {
    returnBusy.value = false
  }
}

const flowText = (r: ResultSheetRow) => (r.approval_flow ? t('gradebook.approval.step', { step: r.approval_flow.step, total: r.approval_flow.total, group: r.approval_flow.group ?? '' }) : '')

const columns = computed(() => [
  { key: 'class', label: t('gradebook.class') },
  { key: 'subject', label: t('gradebook.subject') },
  { key: 'status', label: t('gradebook.validation.status') },
  { key: 'who', label: t('gradebook.approval.submittedBy') },
  { key: 'actions', label: '', align: 'text-right' },
])
</script>

<template>
  <div>
    <GradebookPicker class-optional class="mb-4" @change="onPick" />

    <div class="mb-3 flex flex-wrap gap-1.5">
      <button
        type="button"
        class="rounded-full border px-3 py-1 text-sm"
        :class="status === '' ? 'border-primary-500 bg-primary-50 text-primary-800' : 'border-neutral-200 text-neutral-600'"
        @click="status = ''"
      >
        {{ t('gradebook.approval.all') }} ({{ rows.length }})
      </button>
      <button
        v-for="s in STATUSES"
        :key="s"
        type="button"
        class="rounded-full border px-3 py-1 text-sm"
        :class="status === s ? 'border-primary-500 bg-primary-50 text-primary-800' : 'border-neutral-200 text-neutral-600'"
        @click="status = s"
      >
        {{ t(`gradebook.status.${s}`) }} ({{ count(s) }})
      </button>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="shown.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('gradebook.approval.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="r in shown" :key="r.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <RouterLink :to="entryLink(r)" class="font-semibold text-neutral-900">{{ r.class?.name }} · {{ r.subject?.title }}</RouterLink>
            <BaseBadge :variant="variant[r.status]">{{ t(`gradebook.status.${r.status}`) }}</BaseBadge>
          </div>
          <p v-if="r.requested_by" class="mt-1 text-xs text-neutral-500">{{ r.requested_by }} · {{ formatDateTime(r.submitted_at) }}</p>
          <p v-if="flowText(r)" class="text-xs text-primary-700">{{ flowText(r) }}</p>
          <p v-if="r.decision_note" class="mt-1 text-xs text-amber-700">{{ r.decision_note }}</p>
          <div class="mt-2 flex flex-wrap gap-3 text-sm">
            <button v-if="r.can_submit" type="button" class="font-medium text-secondary-700" :disabled="busy === r.id" @click="act(r, 'submit')">{{ t('gradebook.approval.submit') }}</button>
            <button v-if="r.can_decide" type="button" class="font-medium text-green-700" :disabled="busy === r.id" @click="act(r, 'approve')">{{ t('gradebook.approval.approve') }}</button>
            <button v-if="r.can_decide" type="button" class="font-medium text-red-600" @click="openReturn(r)">{{ t('gradebook.approval.return') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="shown" row-key="id" :loading="loading" :empty-message="t('gradebook.approval.empty')">
        <template #cell-class="{ row }">
          <RouterLink :to="entryLink(row)" class="font-medium text-neutral-900 hover:underline">{{ row.class?.name }}</RouterLink>
        </template>
        <template #cell-subject="{ row }">{{ row.subject?.title }}</template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="variant[row.status as SheetStatus]">{{ t(`gradebook.status.${row.status}`) }}</BaseBadge>
          <p v-if="flowText(row)" class="mt-1 text-xs text-primary-700">{{ flowText(row) }}</p>
          <p v-if="row.decision_note" class="mt-1 max-w-xs text-xs text-amber-700">{{ row.decision_note }}</p>
        </template>
        <template #cell-who="{ row }">
          <span v-if="row.requested_by" class="text-sm">{{ row.requested_by }}</span>
          <p v-if="row.submitted_at" class="text-xs text-neutral-500">{{ formatDateTime(row.submitted_at) }}</p>
          <p v-if="row.decided_by" class="text-xs text-neutral-500">{{ t('gradebook.approval.decidedBy', { name: row.decided_by }) }}</p>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-3 text-sm whitespace-nowrap">
            <button v-if="row.can_submit" type="button" class="font-medium text-secondary-700 hover:underline" :disabled="busy === row.id" @click="act(row, 'submit')">{{ t('gradebook.approval.submit') }}</button>
            <button v-if="row.can_decide" type="button" class="font-medium text-green-700 hover:underline" :disabled="busy === row.id" @click="act(row, 'approve')">{{ t('gradebook.approval.approve') }}</button>
            <button v-if="row.can_decide" type="button" class="font-medium text-red-600 hover:underline" @click="openReturn(row)">{{ t('gradebook.approval.return') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <ConfirmReasonModal
      v-model="returnOpen"
      :title="t('gradebook.approval.returnTitle')"
      :label="t('gradebook.approval.returnReason')"
      :confirm-label="t('gradebook.approval.return')"
      danger
      :submitting="returnBusy"
      :error="returnError"
      @confirm="confirmReturn"
    />
  </div>
</template>

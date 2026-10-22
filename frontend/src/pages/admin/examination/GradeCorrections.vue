<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ConfirmReasonModal from '@/components/admin/ConfirmReasonModal.vue'
import GradebookPicker from '@/components/admin/GradebookPicker.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { resultApprovalsService, type CorrectionStatus, type GradebookPick, type GradeCorrectionRow } from '@/services/gradebook'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

/**
 * Examination > Grade Correction (docs/academics/gradebook.md, 7c): requests
 * to change a score of approved results — old → new and the reason. Approvers
 * (or the Approval Flow group whose turn it is) approve or reject; the
 * requester can withdraw one still waiting. Approved changes are written to
 * the score's history. Asked from Grade Entry (click an approved score).
 */
const { t, locale } = useI18n()
const confirmDialog = useConfirmDialogStore()

const pick = ref<GradebookPick | null>(null)
const rows = ref<GradeCorrectionRow[]>([])
// Spinner until the picker has chosen a term and the first list is in.
const loading = ref(true)
const error = ref<string | null>(null)
const busy = ref<number | null>(null)
const tab = ref<CorrectionStatus>('pending')

const TABS: CorrectionStatus[] = ['pending', 'approved', 'rejected']
const variant: Record<CorrectionStatus, 'primary' | 'success' | 'danger'> = { pending: 'primary', approved: 'success', rejected: 'danger' }
const pickName = (en: string | null | undefined, kh: string | null | undefined) => ((locale.value === 'km' ? kh || en : en || kh) ?? '').trim()

async function load() {
  const p = pick.value
  if (!p?.termId) {
    loading.value = false
    return
  }
  loading.value = true
  error.value = null
  try {
    rows.value = await resultApprovalsService.corrections({ academic_term_id: p.termId, class_id: p.classId ?? undefined })
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

const shown = computed(() => rows.value.filter((r) => r.status === tab.value))
const count = (s: CorrectionStatus) => rows.value.filter((r) => r.status === s).length
const what = (r: GradeCorrectionRow) => {
  const name = pickName(r.what.name, r.what.name_km)
  return r.what.occurrences && r.what.occurrences > 1 ? `${name} ${r.what.occurrence}` : name
}

async function approve(r: GradeCorrectionRow) {
  if (!(await confirmDialog.confirm(t('gradebook.corrections.approveConfirm', { student: r.student?.name ?? '', old: r.old, new: r.new })))) return
  busy.value = r.id
  error.value = null
  try {
    await resultApprovalsService.approveCorrection(r.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.fieldError('status') ?? e.message) : t('gradebook.saveFailed')
  } finally {
    busy.value = null
  }
}

async function withdraw(r: GradeCorrectionRow) {
  if (!(await confirmDialog.confirm({ message: t('gradebook.corrections.withdrawConfirm'), danger: true }))) return
  busy.value = r.id
  try {
    await resultApprovalsService.withdrawCorrection(r.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('gradebook.saveFailed')
  } finally {
    busy.value = null
  }
}

const rejecting = ref<GradeCorrectionRow | null>(null)
const rejectOpen = ref(false)
const rejectBusy = ref(false)
const rejectError = ref<string | null>(null)
function openReject(r: GradeCorrectionRow) {
  rejecting.value = r
  rejectError.value = null
  rejectOpen.value = true
}
async function confirmReject(reason: string) {
  if (!rejecting.value) return
  rejectBusy.value = true
  try {
    await resultApprovalsService.rejectCorrection(rejecting.value.id, reason)
    rejectOpen.value = false
    await load()
  } catch (e) {
    rejectError.value = e instanceof ApiRequestError ? e.message : t('gradebook.saveFailed')
  } finally {
    rejectBusy.value = false
  }
}
</script>

<template>
  <div>
    <GradebookPicker class-optional class="mb-4" @change="onPick" />
    <p class="mb-3 text-sm text-neutral-500">{{ t('gradebook.corrections.hint') }}</p>

    <div class="mb-3 flex gap-1 border-b border-neutral-200">
      <button
        v-for="s in TABS"
        :key="s"
        type="button"
        class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
        :class="tab === s ? 'border-primary-500 text-neutral-900' : 'border-transparent text-neutral-500 hover:text-neutral-800'"
        @click="tab = s"
      >
        {{ t(`gradebook.corrections.status.${s}`) }} ({{ count(s) }})
      </button>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="shown.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('gradebook.corrections.empty') }}
    </p>

    <!-- One card per request; the same on phone and desktop (two per row from lg). -->
    <div v-else class="grid gap-3 lg:grid-cols-2">
      <div v-for="r in shown" :key="r.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="font-semibold text-neutral-900">{{ r.student?.name }}</p>
            <p class="text-xs text-neutral-500">{{ r.reference }} · {{ r.class?.name }} · {{ r.subject?.title }} · {{ r.term?.name }}</p>
          </div>
          <BaseBadge :variant="variant[r.status]">{{ t(`gradebook.corrections.status.${r.status}`) }}</BaseBadge>
        </div>
        <p class="mt-2 text-sm text-neutral-800">
          {{ what(r) }}<span v-if="r.what.max_score"> /{{ r.what.max_score }}</span>:
          <span class="text-neutral-500 line-through">{{ r.old }}</span> → <span class="font-semibold text-primary-700">{{ r.new }}</span>
        </p>
        <p class="mt-1 text-sm whitespace-pre-line text-neutral-600">{{ r.reason }}</p>
        <p class="mt-1 text-xs text-neutral-400">{{ r.requested_by }} · {{ formatDateTime(r.created_at) }}</p>
        <p v-if="r.approval_flow" class="text-xs text-primary-700">{{ t('gradebook.approval.step', { step: r.approval_flow.step, total: r.approval_flow.total, group: r.approval_flow.group ?? '' }) }}</p>
        <p v-if="r.decided_by" class="mt-1 text-xs text-neutral-500">{{ t('gradebook.approval.decidedBy', { name: r.decided_by }) }} · {{ formatDateTime(r.decided_at) }}</p>
        <p v-if="r.decision_reason" class="text-xs text-red-600">{{ r.decision_reason }}</p>
        <div v-if="r.can_decide || r.can_withdraw" class="mt-3 flex flex-wrap gap-3 text-sm">
          <button v-if="r.can_decide" type="button" class="font-medium text-green-700 hover:underline" :disabled="busy === r.id" @click="approve(r)">{{ t('gradebook.approval.approve') }}</button>
          <button v-if="r.can_decide" type="button" class="font-medium text-red-600 hover:underline" @click="openReject(r)">{{ t('gradebook.corrections.reject') }}</button>
          <button v-if="r.can_withdraw" type="button" class="ml-auto text-neutral-500 hover:underline" :disabled="busy === r.id" @click="withdraw(r)">{{ t('gradebook.corrections.withdraw') }}</button>
        </div>
      </div>
    </div>

    <ConfirmReasonModal
      v-model="rejectOpen"
      :title="t('gradebook.corrections.rejectTitle')"
      :label="t('gradebook.approval.returnReason')"
      :confirm-label="t('gradebook.corrections.reject')"
      danger
      :submitting="rejectBusy"
      :error="rejectError"
      @confirm="confirmReject"
    />
  </div>
</template>

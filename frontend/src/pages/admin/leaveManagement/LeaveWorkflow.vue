<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { leaveReportsService, type LeaveWorkflow } from '@/services/leaveManagement'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Leave Management > Approval workflow — how a staff leave request
 * gets decided. It's not set up here: the steps are the "Staff leave" flow
 * in Approval Flow > Flow Setting (each step one approval group, any member
 * of which approves it), and requests are decided in E-Approvals >
 * Approvals. This shows that flow and what's waiting, with links to both.
 */
const { t } = useI18n()
const auth = useAuthStore()

const canEditFlow = computed(() => auth.can('approval-groups.manage'))
const canOpenQueue = computed(() => auth.can('leave-requests.view') || auth.can('leave-requests.approve'))

const workflow = ref<LeaveWorkflow | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    workflow.value = await leaveReportsService.workflow()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.leaveManagement.saveFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.leaveManagement.workflow.hint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else-if="workflow">
      <div class="mb-6 grid grid-cols-3 gap-3">
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
          <p class="text-xs text-neutral-500">{{ t('admin.leaveRequests.statusPending') }}</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ workflow.counts.pending }}</p>
          <p v-if="workflow.oldest_pending_at" class="text-xs text-neutral-500">{{ t('admin.leaveManagement.workflow.oldest', { date: formatDate(workflow.oldest_pending_at) }) }}</p>
        </div>
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
          <p class="text-xs text-neutral-500">{{ t('admin.leaveRequests.statusApproved') }} ({{ workflow.year }})</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ workflow.counts.approved }}</p>
        </div>
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4">
          <p class="text-xs text-neutral-500">{{ t('admin.leaveRequests.statusRejected') }} ({{ workflow.year }})</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ workflow.counts.rejected }}</p>
        </div>
      </div>

      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 sm:p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
          <h3 class="text-sm font-semibold text-neutral-800">{{ t('admin.leaveManagement.workflow.steps') }}</h3>
          <div class="flex flex-wrap gap-2">
            <BaseButton v-if="canOpenQueue" size="sm" to="/admin/approvals/queue">{{ t('admin.leaveManagement.workflow.openQueue') }}</BaseButton>
            <BaseButton v-if="canEditFlow" size="sm" variant="outline" to="/admin/approval-flow/settings">{{ t('admin.leaveManagement.workflow.editFlow') }}</BaseButton>
          </div>
        </div>

        <ol class="space-y-3">
          <li class="flex gap-3">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-semibold text-neutral-600">•</span>
            <div>
              <p class="text-sm font-medium text-neutral-800">{{ t('admin.leaveManagement.workflow.submitted') }}</p>
              <p class="text-xs text-neutral-500">{{ t('admin.leaveManagement.workflow.submittedHint') }}</p>
            </div>
          </li>

          <template v-if="workflow.steps.length > 0">
            <li v-for="step in workflow.steps" :key="step.step_order" class="flex gap-3">
              <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-800">{{ step.step_order }}</span>
              <div class="min-w-0">
                <p class="text-sm font-medium text-neutral-800">{{ step.group.name ?? '—' }}</p>
                <p class="text-xs text-neutral-500">
                  {{ step.group.members.length > 0 ? step.group.members.join(', ') : t('admin.leaveManagement.workflow.noMembers') }}
                </p>
              </div>
            </li>
          </template>
          <li v-else class="flex gap-3">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-800">1</span>
            <div>
              <p class="text-sm font-medium text-neutral-800">{{ t('admin.leaveManagement.workflow.noFlow') }}</p>
              <p class="text-xs text-neutral-500">{{ t('admin.leaveManagement.workflow.noFlowHint') }}</p>
            </div>
          </li>

          <li class="flex gap-3">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-green-100 text-xs font-semibold text-green-800">✓</span>
            <div>
              <p class="text-sm font-medium text-neutral-800">{{ t('admin.leaveManagement.workflow.decided') }}</p>
              <p class="text-xs text-neutral-500">{{ t('admin.leaveManagement.workflow.decidedHint') }}</p>
            </div>
          </li>
        </ol>
      </section>
    </template>
  </div>
</template>

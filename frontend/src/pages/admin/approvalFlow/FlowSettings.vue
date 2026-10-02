<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ApprovalFlowFormModal from '@/components/admin/ApprovalFlowFormModal.vue'
import ApprovalFlowTabs from '@/components/admin/ApprovalFlowTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { approvalFlowsService, type ApprovalDocumentType, type ApprovalFlow } from '@/services/approvalFlows'
import { approvalGroupsService, type ApprovalGroup } from '@/services/approvalGroups'
import { ApiRequestError } from '@/types/api'

/**
 * Approval Flow → Flow Setting: for each item (student permission request,
 * staff leave, …), the groups that approve it, in order. Any one member of
 * a step's group approves that step, then it moves on to the next group.
 * An item with no steps keeps the old rule — whoever holds its approve
 * permission decides.
 */
const { t } = useI18n()

const flows = ref<ApprovalFlow[]>([])
const groups = ref<ApprovalGroup[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const configured = computed(() => flows.value.filter((flow) => flow.steps.length > 0))
const unconfigured = computed(() => flows.value.filter((flow) => flow.steps.length === 0))

async function load() {
  loading.value = true
  error.value = null
  try {
    const [flowList, groupList] = await Promise.all([approvalFlowsService.list(), approvalGroupsService.list({ per_page: 100 })])
    flows.value = flowList
    groups.value = groupList.data
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.approvalFlows.loadFailed')
  } finally {
    loading.value = false
  }
}

const modalOpen = ref(false)
const editingType = ref<ApprovalDocumentType | null>(null)

function openAdd() {
  editingType.value = null
  modalOpen.value = true
}

function openEdit(flow: ApprovalFlow) {
  editingType.value = flow.document_type
  modalOpen.value = true
}

onMounted(load)
</script>

<template>
  <div>
    <ApprovalFlowTabs />

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.approvalFlows.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('admin.approvalFlows.subtitle') }}</p>
      </div>
      <BaseButton :disabled="loading || unconfigured.length === 0" @click="openAdd">{{ t('admin.approvalFlows.addFlow') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else>
      <div class="space-y-3">
        <div
          v-for="flow in configured"
          :key="flow.document_type"
          class="rounded-[--radius-card] border border-neutral-200 bg-white p-4"
        >
          <div class="flex items-start justify-between gap-3">
            <h2 class="font-medium text-neutral-900">{{ t(`admin.approvalFlows.types.${flow.document_type}`) }}</h2>
            <EditIconButton @click="openEdit(flow)" />
          </div>
          <ol class="mt-3 flex flex-wrap items-center gap-2">
            <template v-for="(step, index) in flow.steps" :key="step.step_order">
              <li class="flex items-center gap-2 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-1.5 text-sm">
                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-600 text-xs font-semibold text-white">{{ step.step_order }}</span>
                <span class="font-medium text-neutral-800">{{ step.group.name ?? '—' }}</span>
                <span class="text-xs text-neutral-500">{{ t('admin.approvalFlows.memberCount', step.group.member_count) }}</span>
              </li>
              <li v-if="index < flow.steps.length - 1" aria-hidden="true" class="text-neutral-400">→</li>
            </template>
          </ol>
        </div>
      </div>

      <p v-if="configured.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.approvalFlows.emptyMessage') }}
      </p>

      <div v-if="unconfigured.length > 0" class="mt-6">
        <h2 class="text-sm font-medium text-neutral-700">{{ t('admin.approvalFlows.noFlowTitle') }}</h2>
        <p class="mt-1 text-xs text-neutral-500">{{ t('admin.approvalFlows.noFlowHint') }}</p>
        <div class="mt-2 flex flex-wrap gap-2">
          <button
            v-for="flow in unconfigured"
            :key="flow.document_type"
            type="button"
            class="rounded-full border border-neutral-300 px-3 py-1 text-sm text-neutral-700 hover:border-primary-400 hover:bg-primary-50"
            @click="openEdit(flow)"
          >
            + {{ t(`admin.approvalFlows.types.${flow.document_type}`) }}
          </button>
        </div>
      </div>
    </template>

    <ApprovalFlowFormModal v-model="modalOpen" :flows="flows" :groups="groups" :document-type="editingType" @saved="load" />
  </div>
</template>

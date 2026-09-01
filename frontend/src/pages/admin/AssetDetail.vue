<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import AssignAssetModal from '@/components/admin/AssignAssetModal.vue'
import ChangeAssetConditionModal from '@/components/admin/ChangeAssetConditionModal.vue'
import CompleteAssetRepairModal from '@/components/admin/CompleteAssetRepairModal.vue'
import ConfirmReasonModal from '@/components/admin/ConfirmReasonModal.vue'
import DisposeAssetModal from '@/components/admin/DisposeAssetModal.vue'
import MarkAssetLostModal from '@/components/admin/MarkAssetLostModal.vue'
import ReportAssetIssueModal from '@/components/admin/ReportAssetIssueModal.vue'
import ScheduleAssetMaintenanceModal from '@/components/admin/ScheduleAssetMaintenanceModal.vue'
import SendAssetToRepairModal from '@/components/admin/SendAssetToRepairModal.vue'
import TransferAssetModal from '@/components/admin/TransferAssetModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { assetIssuesService, type AssetIssue } from '@/services/assetIssues'
import { assetMaintenanceService, type AssetMaintenance } from '@/services/assetMaintenance'
import { assetRepairsService, type AssetRepair } from '@/services/assetRepairs'
import { assetsService, type Asset, type AssetCondition, type AssetDocument, type AssetStatus, type DisposalMethod } from '@/services/assets'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const assetId = computed(() => Number(route.params.id))

const asset = ref<Asset | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)

function statusKey(status: AssetStatus): string {
  return status.toLowerCase().split('_').map((p) => p.charAt(0).toUpperCase() + p.slice(1)).join('')
}

function conditionKey(condition: AssetCondition): string {
  return condition.charAt(0) + condition.slice(1).toLowerCase()
}

const statusVariant: Record<AssetStatus, 'neutral' | 'warning' | 'success' | 'danger' | 'primary'> = {
  IN_STOCK: 'neutral', ASSIGNED: 'primary', IN_USE: 'success', ISSUE_REPORTED: 'warning', UNDER_INSPECTION: 'warning',
  BROKEN: 'danger', UNDER_REPAIR: 'warning', REPAIR_COMPLETED: 'primary', READY_FOR_USE: 'success', STOPPED_USE: 'neutral',
  RETIRED: 'neutral', DISPOSED: 'danger', LOST: 'danger', MISSING: 'danger',
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—'
}

async function load() {
  loading.value = true
  loadError.value = null

  try {
    asset.value = await assetsService.get(assetId.value)
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.loadFailed')
  } finally {
    loading.value = false
  }
}

const isClosed = computed(() => asset.value?.status === 'RETIRED' || asset.value?.status === 'DISPOSED')
const isLostOrMissing = computed(() => asset.value?.status === 'LOST' || asset.value?.status === 'MISSING')
const isAssigned = computed(() => asset.value?.current_assignment != null)

// --- Tabs --------------------------------------------------------------

type TabKey = 'overview' | 'assignment' | 'transfers' | 'issues' | 'repairs' | 'maintenance' | 'documents' | 'history'

const tabs: { key: TabKey; labelKey: string }[] = [
  { key: 'overview', labelKey: 'admin.assets.tabOverview' },
  { key: 'assignment', labelKey: 'admin.assets.tabAssignment' },
  { key: 'transfers', labelKey: 'admin.assets.tabTransfers' },
  { key: 'issues', labelKey: 'admin.assets.tabIssues' },
  { key: 'repairs', labelKey: 'admin.assets.tabRepairs' },
  { key: 'maintenance', labelKey: 'admin.assets.tabMaintenance' },
  { key: 'documents', labelKey: 'admin.assets.tabDocuments' },
  { key: 'history', labelKey: 'admin.assets.tabHistory' },
]

const activeTab = ref<TabKey>('overview')
const loadedTabs = new Set<TabKey>(['overview'])

const assignments = usePaginatedResource((query) => assetsService.assignments(assetId.value, query))
const transfers = usePaginatedResource((query) => assetsService.transfers(assetId.value, query))
const issues = usePaginatedResource<AssetIssue>((query) => assetIssuesService.list({ ...query, filter: { ...query.filter, asset_id: String(assetId.value) } }))
const repairs = usePaginatedResource<AssetRepair>((query) => assetRepairsService.list({ ...query, filter: { ...query.filter, asset_id: String(assetId.value) } }))
const maintenance = usePaginatedResource<AssetMaintenance>((query) => assetMaintenanceService.list({ ...query, filter: { ...query.filter, asset_id: String(assetId.value) } }))
const history = usePaginatedResource((query) => assetsService.history(assetId.value, query))

const documents = ref<AssetDocument[]>([])
const documentsLoading = ref(false)

async function loadDocuments() {
  documentsLoading.value = true
  try {
    documents.value = await assetsService.documents(assetId.value)
  } finally {
    documentsLoading.value = false
  }
}

watch(activeTab, (tab) => {
  if (loadedTabs.has(tab)) return
  loadedTabs.add(tab)

  if (tab === 'assignment') void assignments.fetch()
  else if (tab === 'transfers') void transfers.fetch()
  else if (tab === 'issues') void issues.fetch()
  else if (tab === 'repairs') void repairs.fetch()
  else if (tab === 'maintenance') void maintenance.fetch()
  else if (tab === 'documents') void loadDocuments()
  else if (tab === 'history') void history.fetch()
})

// --- Assign / Return -----------------------------------------------------

const assignModalOpen = ref(false)
const assignSubmitting = ref(false)
const assignError = ref<string | null>(null)

async function confirmAssign(payload: Parameters<typeof assetsService.assign>[1]) {
  if (!asset.value) return
  assignSubmitting.value = true
  assignError.value = null

  try {
    await assetsService.assign(asset.value.id, payload)
    assignModalOpen.value = false
    await load()
  } catch (error) {
    assignError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  } finally {
    assignSubmitting.value = false
  }
}

async function returnAsset() {
  if (!asset.value || !window.confirm(t('admin.assets.returnConfirm'))) return
  actionError.value = null

  try {
    asset.value = await assetsService.returnAsset(asset.value.id, {})
  } catch (error) {
    actionError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  }
}

// --- Transfer --------------------------------------------------------------

const transferModalOpen = ref(false)
const transferSubmitting = ref(false)
const transferError = ref<string | null>(null)

async function confirmTransfer(payload: { to_location_id: number | null; to_department_id: number | null; reason?: string }) {
  if (!asset.value) return
  transferSubmitting.value = true
  transferError.value = null

  try {
    await assetsService.transfer(asset.value.id, payload)
    transferModalOpen.value = false
    await load()
    if (loadedTabs.has('transfers')) await transfers.fetch()
  } catch (error) {
    transferError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  } finally {
    transferSubmitting.value = false
  }
}

// --- Change condition --------------------------------------------------------------

const conditionModalOpen = ref(false)
const conditionSubmitting = ref(false)
const conditionError = ref<string | null>(null)

async function confirmChangeCondition(payload: { condition: AssetCondition; notes?: string }) {
  if (!asset.value) return
  conditionSubmitting.value = true
  conditionError.value = null

  try {
    asset.value = await assetsService.changeCondition(asset.value.id, payload.condition, payload.notes)
    conditionModalOpen.value = false
  } catch (error) {
    conditionError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  } finally {
    conditionSubmitting.value = false
  }
}

// --- Retire / Dispose / Lost / Found -----------------------------------

const retireModalOpen = ref(false)
const retireSubmitting = ref(false)
const retireError = ref<string | null>(null)

async function confirmRetire(reason: string) {
  if (!asset.value) return
  retireSubmitting.value = true
  retireError.value = null

  try {
    asset.value = await assetsService.retire(asset.value.id, reason)
    retireModalOpen.value = false
  } catch (error) {
    retireError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  } finally {
    retireSubmitting.value = false
  }
}

const disposeModalOpen = ref(false)
const disposeSubmitting = ref(false)
const disposeError = ref<string | null>(null)

async function confirmDispose(payload: { method: DisposalMethod; reason: string; value?: number }) {
  if (!asset.value) return
  disposeSubmitting.value = true
  disposeError.value = null

  try {
    asset.value = await assetsService.dispose(asset.value.id, payload)
    disposeModalOpen.value = false
  } catch (error) {
    disposeError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  } finally {
    disposeSubmitting.value = false
  }
}

const markLostModalOpen = ref(false)
const markLostSubmitting = ref(false)
const markLostError = ref<string | null>(null)

async function confirmMarkLost(payload: { last_known_location?: string; description?: string }) {
  if (!asset.value) return
  markLostSubmitting.value = true
  markLostError.value = null

  try {
    asset.value = await assetsService.markLost(asset.value.id, payload)
    markLostModalOpen.value = false
  } catch (error) {
    markLostError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  } finally {
    markLostSubmitting.value = false
  }
}

async function markFound() {
  if (!asset.value || !window.confirm(t('admin.assets.markFoundConfirm'))) return
  actionError.value = null

  try {
    asset.value = await assetsService.markFound(asset.value.id)
  } catch (error) {
    actionError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  }
}

// --- Issues --------------------------------------------------------------

const reportIssueModalOpen = ref(false)
const reportIssueSubmitting = ref(false)
const reportIssueError = ref<string | null>(null)

async function confirmReportIssue(payload: Parameters<typeof assetIssuesService.report>[1]) {
  if (!asset.value) return
  reportIssueSubmitting.value = true
  reportIssueError.value = null

  try {
    await assetIssuesService.report(asset.value.id, payload)
    reportIssueModalOpen.value = false
    await load()
    await issues.fetch()
  } catch (error) {
    reportIssueError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  } finally {
    reportIssueSubmitting.value = false
  }
}

async function resolveIssue(issue: AssetIssue) {
  if (!window.confirm(t('admin.assetIssues.resolveConfirm'))) return

  try {
    await assetIssuesService.resolve(issue.id)
    await issues.fetch()
  } catch (error) {
    actionError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  }
}

// --- Repairs --------------------------------------------------------------

const sendToRepairModalOpen = ref(false)
const sendToRepairSubmitting = ref(false)
const sendToRepairError = ref<string | null>(null)

async function confirmSendToRepair(payload: { repair_shop_id: number | null; problem_description: string }) {
  if (!asset.value) return
  sendToRepairSubmitting.value = true
  sendToRepairError.value = null

  try {
    await assetRepairsService.sendToRepair(asset.value.id, payload)
    sendToRepairModalOpen.value = false
    await load()
    await repairs.fetch()
  } catch (error) {
    sendToRepairError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  } finally {
    sendToRepairSubmitting.value = false
  }
}

const completingRepair = ref<AssetRepair | null>(null)
const completeRepairSubmitting = ref(false)
const completeRepairError = ref<string | null>(null)

async function confirmCompleteRepair(payload: Parameters<typeof assetRepairsService.complete>[1]) {
  if (!completingRepair.value) return
  completeRepairSubmitting.value = true
  completeRepairError.value = null

  try {
    await assetRepairsService.complete(completingRepair.value.id, payload)
    completingRepair.value = null
    await load()
    await repairs.fetch()
  } catch (error) {
    completeRepairError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  } finally {
    completeRepairSubmitting.value = false
  }
}

async function cancelRepair(repair: AssetRepair) {
  const reason = window.prompt(t('admin.assetRepairs.cancelReasonPrompt'))
  if (!reason) return

  try {
    await assetRepairsService.cancel(repair.id, reason)
    await repairs.fetch()
    await load()
  } catch (error) {
    actionError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  }
}

// --- Maintenance --------------------------------------------------------------

const scheduleMaintenanceModalOpen = ref(false)
const scheduleMaintenanceSubmitting = ref(false)
const scheduleMaintenanceError = ref<string | null>(null)

async function confirmScheduleMaintenance(payload: Parameters<typeof assetMaintenanceService.schedule>[1]) {
  if (!asset.value) return
  scheduleMaintenanceSubmitting.value = true
  scheduleMaintenanceError.value = null

  try {
    await assetMaintenanceService.schedule(asset.value.id, payload)
    scheduleMaintenanceModalOpen.value = false
    await maintenance.fetch()
  } catch (error) {
    scheduleMaintenanceError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  } finally {
    scheduleMaintenanceSubmitting.value = false
  }
}

async function completeMaintenance(record: AssetMaintenance) {
  const cost = window.prompt(t('admin.assetMaintenance.completeCostPrompt'))
  if (cost === null) return

  try {
    await assetMaintenanceService.complete(record.id, { cost: cost || undefined })
    await maintenance.fetch()
  } catch (error) {
    actionError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  }
}

async function cancelMaintenance(record: AssetMaintenance) {
  if (!window.confirm(t('admin.assetMaintenance.cancelConfirm'))) return

  try {
    await assetMaintenanceService.cancel(record.id)
    await maintenance.fetch()
  } catch (error) {
    actionError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  }
}

// --- Documents --------------------------------------------------------------

const uploading = ref(false)
const uploadError = ref<string | null>(null)

async function onFileSelected(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file || !asset.value) return

  uploading.value = true
  uploadError.value = null

  try {
    await assetsService.uploadDocument(asset.value.id, file)
    await loadDocuments()
  } catch (error) {
    uploadError.value = error instanceof ApiRequestError ? error.message : t('admin.assets.actionFailed')
  } finally {
    uploading.value = false
    ;(event.target as HTMLInputElement).value = ''
  }
}

async function removeDocument(document: AssetDocument) {
  if (!asset.value || !window.confirm(t('admin.assets.removeDocumentConfirm'))) return

  await assetsService.removeDocument(asset.value.id, document.id)
  await loadDocuments()
}

onMounted(load)
</script>

<template>
  <div>
    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <template v-else-if="asset">
      <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold text-neutral-900">{{ asset.asset_number }} — {{ asset.name }}</h1>
            <BaseBadge :variant="statusVariant[asset.status]">{{ t(`admin.assets.status${statusKey(asset.status)}`) }}</BaseBadge>
            <BaseBadge variant="neutral">{{ t(`admin.assets.condition${conditionKey(asset.condition)}`) }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm text-neutral-500">{{ asset.category?.name ?? '—' }}</p>
        </div>
        <div class="flex gap-2">
          <BaseButton variant="outline" :to="`/admin/assets/${asset.id}/edit`">{{ t('common.edit') }}</BaseButton>
          <BaseButton variant="ghost" @click="router.push('/admin/assets')">{{ t('common.change') }}</BaseButton>
        </div>
      </div>

      <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

      <div class="mb-6 flex flex-wrap gap-3">
        <BaseButton v-if="!isClosed && !isAssigned" @click="assignModalOpen = true">{{ t('admin.assets.assign') }}</BaseButton>
        <BaseButton v-if="isAssigned" variant="outline" @click="returnAsset">{{ t('admin.assets.returnAsset') }}</BaseButton>
        <BaseButton v-if="!isClosed" variant="outline" @click="transferModalOpen = true">{{ t('admin.assets.transfer') }}</BaseButton>
        <BaseButton v-if="asset.status !== 'DISPOSED'" variant="outline" @click="conditionModalOpen = true">{{ t('admin.assets.changeCondition') }}</BaseButton>
        <BaseButton v-if="!isClosed" variant="outline" @click="reportIssueModalOpen = true">{{ t('admin.assetIssues.report') }}</BaseButton>
        <BaseButton v-if="!isClosed" variant="outline" @click="sendToRepairModalOpen = true">{{ t('admin.assetRepairs.sendToRepair') }}</BaseButton>
        <BaseButton v-if="!isClosed" variant="outline" @click="scheduleMaintenanceModalOpen = true">{{ t('admin.assetMaintenance.schedule') }}</BaseButton>
        <BaseButton v-if="isLostOrMissing" @click="markFound">{{ t('admin.assets.markFound') }}</BaseButton>
        <BaseButton v-if="!isClosed && !isLostOrMissing" variant="outline" @click="markLostModalOpen = true">{{ t('admin.assets.markLost') }}</BaseButton>
        <BaseButton v-if="asset.status !== 'RETIRED' && asset.status !== 'DISPOSED'" variant="outline" @click="retireModalOpen = true">{{ t('admin.assets.retire') }}</BaseButton>
        <BaseButton v-if="asset.status !== 'DISPOSED'" variant="danger" @click="disposeModalOpen = true">{{ t('admin.assets.dispose') }}</BaseButton>
      </div>

      <div class="mb-4 flex flex-wrap gap-2 border-b border-neutral-200">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          class="border-b-2 px-3 py-2 text-sm font-medium"
          :class="activeTab === tab.key ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
          @click="activeTab = tab.key"
        >
          {{ t(tab.labelKey) }}
        </button>
      </div>

      <!-- Overview -->
      <section v-if="activeTab === 'overview'" class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assets.sectionBasics') }}</h2>
          <dl class="grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-neutral-500">{{ t('admin.assets.brand') }}</dt><dd class="font-medium text-neutral-900">{{ asset.brand ?? '—' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.model') }}</dt><dd class="font-medium text-neutral-900">{{ asset.model ?? '—' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.serialNumber') }}</dt><dd class="font-medium text-neutral-900">{{ asset.serial_number ?? '—' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.assetTag') }}</dt><dd class="font-medium text-neutral-900">{{ asset.asset_tag ?? '—' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.location') }}</dt><dd class="font-medium text-neutral-900">{{ asset.location?.name ?? '—' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.department') }}</dt><dd class="font-medium text-neutral-900">{{ asset.department?.name ?? '—' }}</dd></div>
            <div class="col-span-2"><dt class="text-neutral-500">{{ t('admin.assets.description') }}</dt><dd class="font-medium text-neutral-900">{{ asset.description ?? '—' }}</dd></div>
          </dl>
        </div>

        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assets.sectionTechnical') }}</h2>
          <dl class="grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-neutral-500">{{ t('admin.assets.hostname') }}</dt><dd class="font-medium text-neutral-900">{{ asset.hostname ?? '—' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.macAddress') }}</dt><dd class="font-medium text-neutral-900">{{ asset.mac_address ?? '—' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.ipAddress') }}</dt><dd class="font-medium text-neutral-900">{{ asset.ip_address ?? '—' }}</dd></div>
          </dl>
        </div>

        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assets.sectionPurchase') }}</h2>
          <dl class="grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-neutral-500">{{ t('admin.assets.purchaseDate') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(asset.purchase_date) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.purchasePrice') }}</dt><dd class="font-medium text-neutral-900">${{ asset.purchase_price.toFixed(2) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.currentValue') }}</dt><dd class="font-medium text-neutral-900">{{ asset.current_value !== null ? `$${asset.current_value.toFixed(2)}` : '—' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.supplier') }}</dt><dd class="font-medium text-neutral-900">{{ asset.supplier?.name ?? '—' }}</dd></div>
          </dl>
        </div>

        <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assets.sectionWarranty') }}</h2>
          <dl class="grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-neutral-500">{{ t('admin.assets.warrantyStartDate') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(asset.warranty_start_date) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.warrantyEndDate') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(asset.warranty_end_date) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.warrantyProvider') }}</dt><dd class="font-medium text-neutral-900">{{ asset.warranty_provider ?? '—' }}</dd></div>
            <div>
              <dt class="text-neutral-500">{{ t('admin.assets.warrantyStatus') }}</dt>
              <dd><BaseBadge :variant="asset.warranty_is_active ? 'success' : 'neutral'">{{ asset.warranty_is_active ? t('admin.assets.warrantyActive') : t('admin.assets.warrantyExpired') }}</BaseBadge></dd>
            </div>
          </dl>
        </div>

        <div v-if="asset.status === 'DISPOSED'" class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 lg:col-span-2">
          <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.assets.sectionDisposal') }}</h2>
          <dl class="grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-neutral-500">{{ t('admin.assets.disposalDate') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(asset.disposal_date) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.assets.disposalMethod') }}</dt><dd class="font-medium text-neutral-900">{{ asset.disposal_method ?? '—' }}</dd></div>
            <div class="col-span-2"><dt class="text-neutral-500">{{ t('admin.assets.reason') }}</dt><dd class="font-medium text-neutral-900">{{ asset.disposal_reason ?? '—' }}</dd></div>
          </dl>
        </div>
      </section>

      <!-- Assignment -->
      <section v-else-if="activeTab === 'assignment'">
        <div v-if="asset.current_assignment" class="mb-4 rounded-[--radius-card] border border-neutral-200 bg-white p-5 text-sm">
          <h2 class="mb-2 text-sm font-semibold text-neutral-800">{{ t('admin.assets.currentAssignment') }}</h2>
          <p class="text-neutral-900">{{ asset.current_assignment.assignable_label }}</p>
          <p class="text-neutral-500">{{ t('admin.assets.assignedDate') }}: {{ formatDate(asset.current_assignment.assigned_date) }}</p>
        </div>
        <DataTable
          :columns="[
            { key: 'assignable_label', label: t('admin.assets.assignee') },
            { key: 'assigned_date', label: t('admin.assets.assignedDate') },
            { key: 'returned_date', label: t('admin.assets.returnedDate') },
            { key: 'status', label: t('admin.assets.columnStatus') },
          ]"
          :rows="assignments.items.value"
          row-key="id"
          :loading="assignments.loading.value"
          :empty-message="t('admin.assets.noAssignments')"
        >
          <template #cell-assigned_date="{ row }">{{ formatDate(row.assigned_date) }}</template>
          <template #cell-returned_date="{ row }">{{ formatDate(row.returned_date) }}</template>
        </DataTable>
        <BasePagination v-if="assignments.meta.value" :meta="assignments.meta.value" class="mt-4" @update:page="assignments.setPage" />
      </section>

      <!-- Transfers -->
      <section v-else-if="activeTab === 'transfers'">
        <DataTable
          :columns="[
            { key: 'from_location', label: t('admin.assets.fromLocation') },
            { key: 'to_location', label: t('admin.assets.toLocation') },
            { key: 'transfer_date', label: t('admin.assets.transferDate') },
            { key: 'reason', label: t('admin.assets.reason') },
          ]"
          :rows="transfers.items.value"
          row-key="id"
          :loading="transfers.loading.value"
          :empty-message="t('admin.assets.noTransfers')"
        >
          <template #cell-from_location="{ row }">{{ row.from_location ?? '—' }}</template>
          <template #cell-to_location="{ row }">{{ row.to_location ?? '—' }}</template>
          <template #cell-transfer_date="{ row }">{{ formatDate(row.transfer_date) }}</template>
          <template #cell-reason="{ row }">{{ row.reason ?? '—' }}</template>
        </DataTable>
        <BasePagination v-if="transfers.meta.value" :meta="transfers.meta.value" class="mt-4" @update:page="transfers.setPage" />
      </section>

      <!-- Issues -->
      <section v-else-if="activeTab === 'issues'">
        <DataTable
          :columns="[
            { key: 'issue_number', label: t('admin.assetIssues.columnNumber') },
            { key: 'title', label: t('admin.assetIssues.issueTitle') },
            { key: 'priority', label: t('admin.assetIssues.priority') },
            { key: 'status', label: t('admin.assetIssues.status') },
            { key: 'actions', label: t('admin.assets.columnActions'), align: 'text-right' },
          ]"
          :rows="issues.items.value"
          row-key="id"
          :loading="issues.loading.value"
          :empty-message="t('admin.assetIssues.emptyMessage')"
        >
          <template #cell-priority="{ row }">{{ t(`admin.assetIssues.priority${row.priority.charAt(0)}${row.priority.slice(1).toLowerCase()}`) }}</template>
          <template #cell-status="{ row }">{{ t(`admin.assetIssues.issueStatus${row.status.toLowerCase().split('_').map((p: string) => p.charAt(0).toUpperCase() + p.slice(1)).join('')}`) }}</template>
          <template #cell-actions="{ row }">
            <button
              v-if="!['RESOLVED', 'CLOSED', 'CANCELLED'].includes(row.status)"
              type="button"
              class="text-sm font-medium text-primary-700 hover:underline"
              @click="resolveIssue(row)"
            >
              {{ t('admin.assetIssues.resolve') }}
            </button>
          </template>
        </DataTable>
        <BasePagination v-if="issues.meta.value" :meta="issues.meta.value" class="mt-4" @update:page="issues.setPage" />
      </section>

      <!-- Repairs -->
      <section v-else-if="activeTab === 'repairs'">
        <DataTable
          :columns="[
            { key: 'repair_number', label: t('admin.assetRepairs.columnNumber') },
            { key: 'repair_shop', label: t('admin.assetRepairs.repairShop') },
            { key: 'status', label: t('admin.assetRepairs.status') },
            { key: 'total_cost', label: t('admin.assetRepairs.totalCost'), align: 'text-right' },
            { key: 'actions', label: t('admin.assets.columnActions'), align: 'text-right' },
          ]"
          :rows="repairs.items.value"
          row-key="id"
          :loading="repairs.loading.value"
          :empty-message="t('admin.assetRepairs.emptyMessage')"
        >
          <template #cell-repair_shop="{ row }">{{ row.repair_shop?.name ?? '—' }}</template>
          <template #cell-status="{ row }">{{ t(`admin.assetRepairs.repairStatus${row.status.toLowerCase().split('_').map((p: string) => p.charAt(0).toUpperCase() + p.slice(1)).join('')}`) }}</template>
          <template #cell-total_cost="{ row }">${{ row.total_cost.toFixed(2) }}</template>
          <template #cell-actions="{ row }">
            <div class="flex justify-end gap-3">
              <button
                v-if="!['REPAIR_COMPLETED', 'RETURNED', 'CANCELLED'].includes(row.status)"
                type="button"
                class="text-sm font-medium text-primary-700 hover:underline"
                @click="completingRepair = row"
              >
                {{ t('admin.assetRepairs.complete') }}
              </button>
              <button
                v-if="!['RETURNED', 'CANCELLED'].includes(row.status)"
                type="button"
                class="text-sm font-medium text-danger-600 hover:text-red-700"
                @click="cancelRepair(row)"
              >
                {{ t('common.cancel') }}
              </button>
            </div>
          </template>
        </DataTable>
        <BasePagination v-if="repairs.meta.value" :meta="repairs.meta.value" class="mt-4" @update:page="repairs.setPage" />
      </section>

      <!-- Maintenance -->
      <section v-else-if="activeTab === 'maintenance'">
        <DataTable
          :columns="[
            { key: 'maintenance_number', label: t('admin.assetMaintenance.columnNumber') },
            { key: 'maintenance_type', label: t('admin.assetMaintenance.maintenanceType') },
            { key: 'scheduled_date', label: t('admin.assetMaintenance.scheduledDate') },
            { key: 'status', label: t('admin.assetMaintenance.status') },
            { key: 'actions', label: t('admin.assets.columnActions'), align: 'text-right' },
          ]"
          :rows="maintenance.items.value"
          row-key="id"
          :loading="maintenance.loading.value"
          :empty-message="t('admin.assetMaintenance.emptyMessage')"
        >
          <template #cell-scheduled_date="{ row }">{{ formatDate(row.scheduled_date) }}</template>
          <template #cell-status="{ row }">{{ t(`admin.assetMaintenance.maintenanceStatus${row.status.toLowerCase().split('_').map((p: string) => p.charAt(0).toUpperCase() + p.slice(1)).join('')}`) }}</template>
          <template #cell-actions="{ row }">
            <div class="flex justify-end gap-3">
              <button v-if="row.status === 'SCHEDULED'" type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="completeMaintenance(row)">
                {{ t('admin.assetMaintenance.complete') }}
              </button>
              <button v-if="row.status === 'SCHEDULED'" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="cancelMaintenance(row)">
                {{ t('common.cancel') }}
              </button>
            </div>
          </template>
        </DataTable>
        <BasePagination v-if="maintenance.meta.value" :meta="maintenance.meta.value" class="mt-4" @update:page="maintenance.setPage" />
      </section>

      <!-- Documents -->
      <section v-else-if="activeTab === 'documents'" class="rounded-[--radius-card] border border-neutral-200 bg-white p-5">
        <BaseAlert v-if="uploadError" variant="danger" class="mb-3">{{ uploadError }}</BaseAlert>
        <BaseSpinner v-if="documentsLoading" class="mx-auto" />
        <template v-else>
          <ul v-if="documents.length > 0" class="mb-3 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
            <li v-for="document in documents" :key="document.id" class="flex items-center justify-between px-3 py-2 text-sm">
              <a :href="document.url" target="_blank" rel="noopener noreferrer" class="text-primary-700 hover:underline">{{ document.file_name }}</a>
              <button type="button" class="text-danger-600 hover:text-red-700" @click="removeDocument(document)">{{ t('common.remove') }}</button>
            </li>
          </ul>
          <p v-else class="mb-3 text-sm text-neutral-400">{{ t('admin.assets.noDocuments') }}</p>
        </template>

        <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-primary-700">
          <input type="file" class="hidden" :disabled="uploading" accept=".pdf,.jpg,.jpeg,.png,.webp" @change="onFileSelected" />
          {{ uploading ? t('common.loading') : t('admin.assets.uploadDocument') }}
        </label>
      </section>

      <!-- History -->
      <section v-else-if="activeTab === 'history'">
        <DataTable
          :columns="[
            { key: 'occurred_at', label: t('admin.assets.historyDate') },
            { key: 'event_type', label: t('admin.assets.historyEvent') },
            { key: 'description', label: t('admin.assets.historyDescription') },
            { key: 'actor', label: t('admin.assets.historyActor') },
          ]"
          :rows="history.items.value"
          row-key="id"
          :loading="history.loading.value"
          :empty-message="t('admin.assets.noHistory')"
        >
          <template #cell-occurred_at="{ row }">{{ new Date(row.occurred_at).toLocaleString() }}</template>
          <template #cell-actor="{ row }">{{ row.actor ?? '—' }}</template>
        </DataTable>
        <BasePagination v-if="history.meta.value" :meta="history.meta.value" class="mt-4" @update:page="history.setPage" />
      </section>
    </template>

    <AssignAssetModal v-model="assignModalOpen" :submitting="assignSubmitting" :error="assignError" @confirm="confirmAssign" />
    <TransferAssetModal
      v-model="transferModalOpen"
      :current-location-id="asset?.location_id"
      :current-department-id="asset?.department_id"
      :submitting="transferSubmitting"
      :error="transferError"
      @confirm="confirmTransfer"
    />
    <ChangeAssetConditionModal
      v-model="conditionModalOpen"
      :current-condition="asset?.condition"
      :submitting="conditionSubmitting"
      :error="conditionError"
      @confirm="confirmChangeCondition"
    />
    <ConfirmReasonModal
      v-model="retireModalOpen"
      :title="t('admin.assets.retireTitle')"
      :label="t('admin.assets.reason')"
      :confirm-label="t('admin.assets.retire')"
      danger
      :submitting="retireSubmitting"
      :error="retireError"
      @confirm="confirmRetire"
    />
    <DisposeAssetModal v-model="disposeModalOpen" :submitting="disposeSubmitting" :error="disposeError" @confirm="confirmDispose" />
    <MarkAssetLostModal v-model="markLostModalOpen" :submitting="markLostSubmitting" :error="markLostError" @confirm="confirmMarkLost" />
    <ReportAssetIssueModal v-model="reportIssueModalOpen" :submitting="reportIssueSubmitting" :error="reportIssueError" @confirm="confirmReportIssue" />
    <SendAssetToRepairModal v-model="sendToRepairModalOpen" :submitting="sendToRepairSubmitting" :error="sendToRepairError" @confirm="confirmSendToRepair" />
    <CompleteAssetRepairModal
      :model-value="completingRepair !== null"
      :submitting="completeRepairSubmitting"
      :error="completeRepairError"
      @update:model-value="completingRepair = null"
      @confirm="confirmCompleteRepair"
    />
    <ScheduleAssetMaintenanceModal
      v-model="scheduleMaintenanceModalOpen"
      :submitting="scheduleMaintenanceSubmitting"
      :error="scheduleMaintenanceError"
      @confirm="confirmScheduleMaintenance"
    />
  </div>
</template>

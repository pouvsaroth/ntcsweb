<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import ReviewForm from '@/pages/admin/performance/ReviewForm.vue'
import { reviewStatusVariant } from '@/pages/admin/performance/reviewStatus'
import {
  performanceCyclesService,
  performanceReviewsService,
  scoreLabel,
  type PerformanceCycle,
  type PerformanceReviewDetail,
  type PerformanceReviewRow,
  type ReviewCandidate,
  type ReviewKpi,
  type ReviewStatus,
} from '@/services/performance'
import { staffService, type Staff } from '@/services/staff'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HR's side of a cycle's reviews (Performance review tab): launch reviews
 * for staff — each gets the KPIs that apply to them and the cycle's form,
 * with their Reports-to as manager — then follow them, change a reviewer
 * or KPIs, send one on to the manager, reopen a completed one. Cards on a
 * phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('performance.manage'))

const cycles = ref<PerformanceCycle[]>([])
const cycleId = ref('')
const statusFilter = ref('')
const cycleOptions = computed(() => cycles.value.map((c) => ({ value: String(c.id), label: c.name })))
const statusOptions = computed(() => [
  { value: '', label: t('admin.performance.goals.allStatuses') },
  ...(['self_assessment', 'manager_assessment', 'completed'] as ReviewStatus[]).map((s) => ({ value: s, label: t(`admin.performance.review.status.${s}`) })),
])

const { items, meta, loading, error, setPage, setSearch, setFilter } = usePaginatedResource<PerformanceReviewRow>((query) => performanceReviewsService.list(query))
watch(cycleId, (id) => setFilter('performance_cycle_id', id || undefined))
watch(statusFilter, (status) => setFilter('status', status || undefined))
const actionError = ref<string | null>(null)

async function loadCycles() {
  cycles.value = await performanceCyclesService.listAll().catch(() => [])
  const active = cycles.value.find((c) => c.status === 'active') ?? cycles.value[0]
  cycleId.value = active ? String(active.id) : ''
}

defineExpose({ loadCycles })

// --- Launch ----------------------------------------------------------------------------

const launchOpen = ref(false)
const candidates = ref<ReviewCandidate[]>([])
const picked = ref<Set<number>>(new Set())
const launching = ref(false)
const launchError = ref<string | null>(null)
const launchMessage = ref<string | null>(null)

async function openLaunch() {
  launchError.value = null
  launchOpen.value = true
  candidates.value = await performanceReviewsService.candidates(Number(cycleId.value)).catch(() => [])
  picked.value = new Set(candidates.value.map((c) => c.id))
}

function toggle(id: number) {
  const next = new Set(picked.value)
  next.has(id) ? next.delete(id) : next.add(id)
  picked.value = next
}

async function launch() {
  launching.value = true
  launchError.value = null
  try {
    const result = await performanceReviewsService.launch(Number(cycleId.value), [...picked.value])
    launchOpen.value = false
    launchMessage.value = t('admin.performance.review.launched', { count: result.launched })
    setFilter('performance_cycle_id', cycleId.value)
    await loadCycles()
  } catch (e) {
    launchError.value = e instanceof ApiRequestError ? e.message : t('admin.performance.saveFailed')
  } finally {
    launching.value = false
  }
}

// --- One review --------------------------------------------------------------------------

const open = ref<PerformanceReviewDetail | null>(null)
const busy = ref(false)
const detailError = ref<string | null>(null)
const staff = ref<Staff[]>([])
const reviewerId = ref('')
const staffOptions = computed(() => staff.value.filter((s) => s.id !== open.value?.staff?.id).map((s) => ({ value: String(s.id), label: s.full_name, hint: s.employee_code })))

async function openReview(row: PerformanceReviewRow) {
  detailError.value = null
  open.value = await performanceReviewsService.get(row.id)
  reviewerId.value = open.value.reviewer ? String(open.value.reviewer.id) : ''
  if (staff.value.length === 0) staff.value = await staffService.listAll().catch(() => [])
}

async function act(action: () => Promise<PerformanceReviewDetail>) {
  busy.value = true
  detailError.value = null
  try {
    open.value = await action()
    await refresh()
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.performance.saveFailed')
  } finally {
    busy.value = false
  }
}

async function refresh() {
  setFilter('performance_cycle_id', cycleId.value || undefined)
}

const saveReviewer = () => act(() => performanceReviewsService.setReviewer(open.value!.id, reviewerId.value ? Number(reviewerId.value) : null))
const sendToManager = () => act(() => performanceReviewsService.sendToManager(open.value!.id))
const reopen = () => act(() => performanceReviewsService.reopen(open.value!.id))

async function remove() {
  if (!open.value || !(await confirmDialog.confirm({ message: t('admin.performance.review.deleteConfirm', { name: open.value.staff?.name ?? '' }), danger: true }))) return
  busy.value = true
  try {
    await performanceReviewsService.remove(open.value.id)
    open.value = null
    await refresh()
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  } finally {
    busy.value = false
  }
}

// --- A review's KPIs ----------------------------------------------------------------------

const kpisOpen = ref(false)
const kpiRows = ref<Partial<ReviewKpi>[]>([])
const kpiErrors = ref<Record<string, string[]>>({})

function openKpis() {
  kpiRows.value = (open.value?.kpis ?? []).map((k) => ({ id: k.id, name: k.name, unit: k.unit, target: k.target, higher_is_better: k.higher_is_better, weight: k.weight }))
  kpiErrors.value = {}
  kpisOpen.value = true
}

async function saveKpis() {
  kpiErrors.value = {}
  try {
    open.value = await performanceReviewsService.replaceKpis(open.value!.id, kpiRows.value.map((k) => ({ ...k, target: k.target === null || (k.target as unknown) === '' ? null : Number(k.target), weight: Number(k.weight ?? 0) })))
    kpisOpen.value = false
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) kpiErrors.value = e.errors
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.performance.saveFailed')
  }
}

onMounted(() => loadCycles())
</script>

<template>
  <section>
    <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.performance.review.reviewsTitle') }}</h2>
        <p class="text-sm text-neutral-500">{{ t('admin.performance.review.reviewsHint') }}</p>
      </div>
      <BaseButton v-if="canManage && cycleId" @click="openLaunch">{{ t('admin.performance.review.launch') }}</BaseButton>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-3">
      <BaseSelect v-model="cycleId" class="w-full sm:w-56" :options="cycleOptions" :placeholder="t('admin.performance.review.pickCycle')" />
      <BaseSelect v-model="statusFilter" class="w-full sm:w-52" :options="statusOptions" />
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <BaseAlert v-if="launchMessage" variant="success" class="mb-3">{{ launchMessage }}</BaseAlert>
    <BaseAlert v-if="error || actionError" variant="danger" class="mb-3">{{ error || actionError }}</BaseAlert>

    <p v-if="!cycleId" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.performance.review.noCycle') }}</p>
    <div v-else-if="loading" class="flex justify-center py-8"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.performance.review.noReviews') }}</p>
    <template v-else>
      <div class="space-y-2 sm:hidden">
        <button v-for="row in items" :key="row.id" type="button" class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]" @click="openReview(row)">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ row.staff?.name }} <span class="font-normal text-neutral-500">({{ row.staff?.employee_code }})</span></p>
            <BaseBadge :variant="reviewStatusVariant[row.status]">{{ t(`admin.performance.review.status.${row.status}`) }}</BaseBadge>
          </div>
          <p class="text-xs text-neutral-500">{{ t('admin.performance.review.manager') }}: {{ row.reviewer?.name ?? t('admin.performance.review.noManager') }}</p>
          <p v-if="row.final_score !== null" class="text-sm font-semibold text-neutral-900">{{ scoreLabel(row.final_score) }}</p>
        </button>
      </div>
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-3 font-medium">{{ t('admin.performance.staff') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.performance.review.manager') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.organization.status') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.performance.score.final') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in items" :key="row.id" class="cursor-pointer hover:bg-neutral-50" @click="openReview(row)">
              <td class="px-4 py-3">
                <p class="font-medium text-primary-700">{{ row.staff?.name }}</p>
                <p class="text-xs text-neutral-500">{{ row.staff?.employee_code }}<template v-if="row.staff?.position"> · {{ row.staff.position }}</template></p>
              </td>
              <td class="px-4 py-3" :class="row.reviewer ? 'text-neutral-700' : 'text-amber-700'">{{ row.reviewer?.name ?? t('admin.performance.review.noManager') }}</td>
              <td class="px-4 py-3"><BaseBadge :variant="reviewStatusVariant[row.status]">{{ t(`admin.performance.review.status.${row.status}`) }}</BaseBadge></td>
              <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ scoreLabel(row.final_score) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
    <BasePagination v-if="meta && cycleId" :meta="meta" class="mt-3" @update:page="setPage" />

    <!-- Launch -->
    <BaseModal v-model="launchOpen" size="lg" :title="t('admin.performance.review.launch')">
      <BaseAlert v-if="launchError" variant="danger" class="mb-3">{{ launchError }}</BaseAlert>
      <p class="mb-3 text-sm text-neutral-600">{{ t('admin.performance.review.launchHint') }}</p>
      <p v-if="candidates.length === 0" class="rounded-lg bg-neutral-50 px-3 py-6 text-center text-sm text-neutral-500">{{ t('admin.performance.review.noCandidates') }}</p>
      <template v-else>
        <div class="mb-2 flex gap-3 text-sm">
          <button type="button" class="font-medium text-primary-700 hover:underline" @click="picked = new Set(candidates.map((c) => c.id))">{{ t('admin.performance.review.selectAll') }}</button>
          <button type="button" class="font-medium text-primary-700 hover:underline" @click="picked = new Set()">{{ t('admin.performance.review.selectNone') }}</button>
          <span class="ml-auto text-neutral-500">{{ t('admin.performance.review.pickedN', { count: picked.size }) }}</span>
        </div>
        <ul class="max-h-96 divide-y divide-neutral-100 overflow-y-auto rounded-lg border border-neutral-200">
          <li v-for="candidate in candidates" :key="candidate.id">
            <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-neutral-50">
              <input type="checkbox" :checked="picked.has(candidate.id)" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" @change="toggle(candidate.id)" />
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-neutral-800">{{ candidate.name }} <span class="font-normal text-neutral-500">({{ candidate.employee_code }})</span></span>
                <span class="block text-xs" :class="candidate.reports_to ? 'text-neutral-500' : 'text-amber-700'">
                  {{ candidate.reports_to ? t('admin.performance.review.reportsTo', { name: candidate.reports_to.name }) : t('admin.performance.review.noReportsTo') }}
                </span>
              </span>
            </label>
          </li>
        </ul>
      </template>
      <template #footer>
        <BaseButton variant="outline" @click="launchOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="launching" :disabled="picked.size === 0" @click="launch">{{ t('admin.performance.review.launchN', { count: picked.size }) }}</BaseButton>
      </template>
    </BaseModal>

    <!-- One review -->
    <BaseModal :model-value="open !== null" size="lg" :title="open ? `${open.staff?.name ?? ''} — ${open.cycle?.name ?? ''}` : ''" @update:model-value="open = null">
      <template v-if="open">
        <BaseAlert v-if="detailError" variant="danger" class="mb-3">{{ detailError }}</BaseAlert>
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg bg-neutral-50 p-3">
          <BaseBadge :variant="reviewStatusVariant[open.status]">{{ t(`admin.performance.review.status.${open.status}`) }}</BaseBadge>
          <SearchableSelect v-if="canManage" v-model="reviewerId" class="min-w-56 flex-1" :options="staffOptions" :label="t('admin.performance.review.manager')" />
          <span v-else class="text-sm text-neutral-700">{{ t('admin.performance.review.manager') }}: {{ open.reviewer?.name ?? '—' }}</span>
          <BaseButton v-if="canManage && reviewerId !== String(open.reviewer?.id ?? '')" size="sm" :loading="busy" @click="saveReviewer">{{ t('common.save') }}</BaseButton>
        </div>
        <ReviewForm :review="open" side="hr" :editable="false" />
      </template>
      <template #footer>
        <template v-if="open && canManage">
          <BaseButton v-if="open.status !== 'completed'" variant="outline" :disabled="busy" @click="openKpis">{{ t('admin.performance.review.editKpis') }}</BaseButton>
          <BaseButton v-if="open.status === 'self_assessment'" variant="outline" :loading="busy" @click="sendToManager">{{ t('admin.performance.review.skipSelf') }}</BaseButton>
          <BaseButton v-if="open.status === 'completed'" variant="outline" :loading="busy" @click="reopen">{{ t('admin.performance.review.reopen') }}</BaseButton>
          <BaseButton v-if="open.status !== 'completed'" variant="danger" :disabled="busy" @click="remove">{{ t('admin.organization.delete') }}</BaseButton>
        </template>
        <BaseButton variant="outline" @click="open = null">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- A review's KPIs -->
    <BaseModal v-model="kpisOpen" size="lg" :title="t('admin.performance.review.editKpis')">
      <p class="mb-3 text-sm text-neutral-600">{{ t('admin.performance.review.editKpisHint') }}</p>
      <div v-for="(kpi, index) in kpiRows" :key="index" class="mb-2 grid grid-cols-[minmax(0,1fr)_6rem_5rem_5rem_auto] items-start gap-2">
        <BaseInput :model-value="kpi.name ?? ''" :placeholder="t('admin.performance.kpis.name')" :error="kpiErrors[`kpis.${index}.name`]?.[0]" @update:model-value="kpi.name = $event" />
        <BaseInput :model-value="kpi.target === null || kpi.target === undefined ? '' : String(kpi.target)" type="number" :placeholder="t('admin.performance.kpis.target')" @update:model-value="kpi.target = $event === '' ? null : Number($event)" />
        <BaseInput :model-value="kpi.unit ?? ''" :placeholder="t('admin.performance.kpis.unit')" @update:model-value="kpi.unit = $event || null" />
        <BaseInput :model-value="String(kpi.weight ?? 0)" type="number" placeholder="%" @update:model-value="kpi.weight = Number($event || 0)" />
        <button type="button" class="mt-2 text-sm font-medium text-danger-600" :aria-label="t('admin.organization.delete')" @click="kpiRows.splice(index, 1)">✕</button>
      </div>
      <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="kpiRows.push({ name: '', target: null, unit: null, weight: 0, higher_is_better: true })">+ {{ t('admin.performance.kpis.add') }}</button>
      <template #footer>
        <BaseButton variant="outline" @click="kpisOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton @click="saveKpis">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </section>
</template>

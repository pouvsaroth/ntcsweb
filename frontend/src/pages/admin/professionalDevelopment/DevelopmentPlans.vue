<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import DevelopmentPlanDetailModal from '@/components/admin/DevelopmentPlanDetailModal.vue'
import DevelopmentPlanFormModal from '@/components/admin/DevelopmentPlanFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  PLAN_STATUSES,
  developmentPlansService,
  trainingsService,
  type DevelopmentPlan,
  type PdStaffOption,
  type PlanMine,
  type PlanSummary,
} from '@/services/professionalDevelopment'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/utils/date'
import { planStatusVariant } from '@/utils/professionalDevelopment'

/**
 * HRM > Professional Development > Development plan. Managers write and
 * follow every plan; everyone else sees their own plan and the ones they
 * review (once shared).
 */
const { t } = useI18n()
const auth = useAuthStore()
const canViewAll = computed(() => auth.can('professional-development.view'))
const canManage = computed(() => auth.can('professional-development.manage'))

const mine = ref<PlanMine>('')
const status = ref('')

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<DevelopmentPlan>((query) =>
  developmentPlansService.list({ ...query, mine: mine.value, status: status.value || undefined }),
)

const summary = ref<PlanSummary | null>(null)
async function loadSummary() {
  if (canViewAll.value) summary.value = await developmentPlansService.summary().catch(() => null)
}
const cards = computed(() => [
  { key: 'active', label: t('proDev.plans.cards.active'), value: String(summary.value?.active ?? 0), tone: 'text-primary-700' },
  { key: 'drafts', label: t('proDev.plans.cards.drafts'), value: String(summary.value?.drafts ?? 0), tone: 'text-neutral-900' },
  {
    key: 'done',
    label: t('proDev.plans.cards.goalsDone'),
    value: summary.value?.goals_done_percent != null ? `${summary.value.goals_done_percent}%` : '—',
    tone: 'text-green-700',
  },
  { key: 'overdue', label: t('proDev.plans.cards.overdue'), value: String(summary.value?.overdue_goals ?? 0), tone: 'text-amber-700' },
])

function refresh() {
  void fetch()
  void loadSummary()
}

const mineOptions = computed(() => [
  { value: '' as const, label: canViewAll.value ? t('proDev.all') : t('proDev.observations.mineAll') },
  { value: 'own' as const, label: t('proDev.plans.mineOwn') },
  { value: 'reviewing' as const, label: t('proDev.plans.mineReviewing') },
])
const statusOptions = computed(() => [{ value: '', label: t('proDev.allStatuses') }, ...PLAN_STATUSES.map((s) => ({ value: s, label: t(`proDev.planStatuses.${s}`) }))])

function setMine(v: PlanMine) {
  mine.value = v
  setPage(1)
}

const columns = computed(() => [
  { key: 'title', label: t('proDev.plans.planTitle') },
  { key: 'staff', label: t('proDev.plans.staff') },
  { key: 'reviewer', label: t('proDev.plans.reviewer') },
  { key: 'period', label: t('proDev.plans.period') },
  { key: 'progress', label: t('proDev.plans.progress') },
  { key: 'status', label: t('proDev.trainings.status') },
])

// --- Popups -----------------------------------------------------------------
const staff = ref<PdStaffOption[]>([])
const formOpen = ref(false)
const editing = ref<DevelopmentPlan | null>(null)
const detailOpen = ref(false)
const detailId = ref<number | null>(null)

async function openForm(p: DevelopmentPlan | null) {
  if (staff.value.length === 0) staff.value = await trainingsService.staffOptions().catch(() => [])
  editing.value = p
  detailOpen.value = false
  formOpen.value = true
}
function openDetail(p: DevelopmentPlan) {
  detailId.value = p.id
  detailOpen.value = true
}
function onSaved(p: DevelopmentPlan) {
  refresh()
  openDetail(p)
}

const selectClass =
  'rounded-lg border border-neutral-300 py-2 pl-3 pr-9 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200'

onMounted(refresh)
</script>

<template>
  <div>
    <div v-if="canViewAll" class="mb-5 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
      <div v-for="card in cards" :key="card.key" class="rounded-[--radius-card] border border-neutral-200 bg-white p-2.5 shadow-[--shadow-card] sm:p-4">
        <p class="truncate text-xs font-medium text-neutral-500">{{ card.label }}</p>
        <p class="mt-0.5 text-xl font-semibold sm:mt-1 sm:text-2xl" :class="card.tone">{{ card.value }}</p>
      </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="inline-flex rounded-lg border border-neutral-300 bg-white p-0.5 shadow-sm" role="group">
        <button
          v-for="o in mineOptions"
          :key="o.value"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="mine === o.value ? 'bg-primary-500 text-secondary-900' : 'text-neutral-600 hover:bg-neutral-100'"
          :aria-pressed="mine === o.value"
          @click="setMine(o.value)"
        >
          {{ o.label }}
        </button>
      </div>
      <select v-model="status" :class="selectClass" :aria-label="t('proDev.trainings.status')" @change="setPage(1)">
        <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <input
        type="search"
        :placeholder="t('proDev.plans.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="canManage" class="sm:ml-auto" @click="openForm(null)">{{ t('proDev.plans.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('proDev.plans.empty')">
        <template #cell-title="{ row }">
          <button type="button" class="text-left font-medium text-neutral-900 hover:underline" @click="openDetail(row)">{{ row.title }}</button>
        </template>
        <template #cell-staff="{ row }">{{ row.staff?.name ?? '—' }}</template>
        <template #cell-reviewer="{ row }">{{ row.reviewer?.name ?? '—' }}</template>
        <template #cell-period="{ row }">{{ formatDate(row.start_date) }}<template v-if="row.end_date"> – {{ formatDate(row.end_date) }}</template></template>
        <template #cell-progress="{ row }">
          <div class="flex min-w-[8rem] items-center gap-2">
            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-200">
              <div class="h-full rounded-full bg-primary-500" :style="{ width: `${row.progress}%` }" />
            </div>
            <span class="text-xs text-neutral-700">{{ row.progress }}%</span>
          </div>
          <span class="block text-xs text-neutral-500">{{ t('proDev.plans.doneOf', { done: row.goals_done, total: row.goals_count }) }}</span>
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="planStatusVariant(row.status)">{{ t(`proDev.planStatuses.${row.status}`) }}</BaseBadge>
        </template>
      </DataTable>
    </div>

    <div class="space-y-2 sm:hidden">
      <p v-if="loading && items.length === 0" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="items.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('proDev.plans.empty') }}</p>
      <button v-for="row in items" :key="row.id" type="button" class="block w-full rounded-lg border border-neutral-200 bg-white p-3 text-left shadow-sm" @click="openDetail(row)">
        <div class="flex items-start justify-between gap-2">
          <p class="min-w-0 truncate font-medium text-neutral-900">{{ row.title }}</p>
          <BaseBadge :variant="planStatusVariant(row.status)">{{ t(`proDev.planStatuses.${row.status}`) }}</BaseBadge>
        </div>
        <p class="mt-1 text-sm text-neutral-700">{{ row.staff?.name }}</p>
        <div class="mt-2 flex items-center gap-2">
          <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-200">
            <div class="h-full rounded-full bg-primary-500" :style="{ width: `${row.progress}%` }" />
          </div>
          <span class="text-xs text-neutral-700">{{ row.progress }}% · {{ t('proDev.plans.doneOf', { done: row.goals_done, total: row.goals_count }) }}</span>
        </div>
      </button>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <DevelopmentPlanFormModal v-model="formOpen" :plan="editing" :staff="staff" @saved="onSaved" />
    <DevelopmentPlanDetailModal v-model="detailOpen" :plan-id="detailId" @changed="refresh" @edit="openForm" />
  </div>
</template>

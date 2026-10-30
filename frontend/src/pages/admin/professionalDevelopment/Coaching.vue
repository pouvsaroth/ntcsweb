<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CoachingDetailModal from '@/components/admin/CoachingDetailModal.vue'
import CoachingFormModal from '@/components/admin/CoachingFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  COACHING_STATUSES,
  COACHING_TYPES,
  coachingService,
  trainingsService,
  type Coaching,
  type CoachingMine,
  type CoachingSummary,
  type PdStaffOption,
} from '@/services/professionalDevelopment'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/utils/date'
import { coachingStatusVariant } from '@/utils/professionalDevelopment'

/**
 * HRM > Professional Development > Coaching & mentoring. Managers see and
 * set up every pairing; everyone else sees the ones they coach or receive.
 */
const { t } = useI18n()
const auth = useAuthStore()
const canViewAll = computed(() => auth.can('professional-development.view'))
const canManage = computed(() => auth.can('professional-development.manage'))

const mine = ref<CoachingMine>('')
const status = ref('active')
const type = ref('')

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Coaching>((query) =>
  coachingService.list({ ...query, mine: mine.value, status: status.value || undefined, type: type.value || undefined }),
)

const summary = ref<CoachingSummary | null>(null)
async function loadSummary() {
  if (canViewAll.value) summary.value = await coachingService.summary().catch(() => null)
}
const cards = computed(() => [
  { key: 'active', label: t('proDev.coaching.cards.active'), value: summary.value?.active ?? 0, tone: 'text-primary-700' },
  { key: 'completed', label: t('proDev.coaching.cards.completedThisYear'), value: summary.value?.completed_this_year ?? 0, tone: 'text-green-700' },
  { key: 'sessions', label: t('proDev.coaching.cards.sessionsThisMonth'), value: summary.value?.sessions_this_month ?? 0, tone: 'text-neutral-900' },
  { key: 'coaches', label: t('proDev.coaching.cards.coaches'), value: summary.value?.coaches ?? 0, tone: 'text-amber-700' },
])

function refresh() {
  void fetch()
  void loadSummary()
}

const mineOptions = computed(() => [
  { value: '' as const, label: canViewAll.value ? t('proDev.all') : t('proDev.observations.mineAll') },
  { value: 'coach' as const, label: t('proDev.coaching.mineCoach') },
  { value: 'coached' as const, label: t('proDev.coaching.mineCoached') },
])
const statusOptions = computed(() => [{ value: '', label: t('proDev.allStatuses') }, ...COACHING_STATUSES.map((s) => ({ value: s, label: t(`proDev.coachingStatuses.${s}`) }))])
const typeOptions = computed(() => [{ value: '', label: t('proDev.coaching.allTypes') }, ...COACHING_TYPES.map((s) => ({ value: s, label: t(`proDev.coachingTypes.${s}`) }))])

function setMine(v: CoachingMine) {
  mine.value = v
  setPage(1)
}

const columns = computed(() => [
  { key: 'goal', label: t('proDev.coaching.goal') },
  { key: 'coachee', label: t('proDev.coaching.coachee') },
  { key: 'coach', label: t('proDev.coaching.coach') },
  { key: 'period', label: t('proDev.coaching.period') },
  { key: 'sessions', label: t('proDev.coaching.sessions') },
  { key: 'status', label: t('proDev.trainings.status') },
])

// --- Popups -----------------------------------------------------------------
const staff = ref<PdStaffOption[]>([])
const formOpen = ref(false)
const editing = ref<Coaching | null>(null)
const detailOpen = ref(false)
const detailId = ref<number | null>(null)

async function openForm(c: Coaching | null) {
  if (staff.value.length === 0) staff.value = await trainingsService.staffOptions().catch(() => [])
  editing.value = c
  detailOpen.value = false
  formOpen.value = true
}
function openDetail(c: Coaching) {
  detailId.value = c.id
  detailOpen.value = true
}
function onSaved(c: Coaching) {
  refresh()
  if (!editing.value) openDetail(c)
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
      <select v-model="type" :class="selectClass" :aria-label="t('proDev.coaching.type')" @change="setPage(1)">
        <option v-for="o in typeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <input
        type="search"
        :placeholder="t('proDev.coaching.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="canManage" class="sm:ml-auto" @click="openForm(null)">{{ t('proDev.coaching.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('proDev.coaching.empty')">
        <template #cell-goal="{ row }">
          <button type="button" class="text-left font-medium text-neutral-900 hover:underline" @click="openDetail(row)">{{ row.goal }}</button>
          <span class="block text-xs text-neutral-500">{{ t(`proDev.coachingTypes.${row.type}`) }}</span>
        </template>
        <template #cell-coachee="{ row }">{{ row.staff?.name ?? '—' }}</template>
        <template #cell-coach="{ row }">{{ row.coach?.name ?? '—' }}</template>
        <template #cell-period="{ row }">{{ formatDate(row.start_date) }}<template v-if="row.end_date"> – {{ formatDate(row.end_date) }}</template></template>
        <template #cell-sessions="{ row }">
          {{ row.sessions_count }}
          <span v-if="row.last_session_date" class="block text-xs text-neutral-500">{{ t('proDev.coaching.lastSession', { date: formatDate(row.last_session_date) }) }}</span>
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="coachingStatusVariant(row.status)">{{ t(`proDev.coachingStatuses.${row.status}`) }}</BaseBadge>
        </template>
      </DataTable>
    </div>

    <div class="space-y-2 sm:hidden">
      <p v-if="loading && items.length === 0" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="items.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('proDev.coaching.empty') }}</p>
      <button v-for="row in items" :key="row.id" type="button" class="block w-full rounded-lg border border-neutral-200 bg-white p-3 text-left shadow-sm" @click="openDetail(row)">
        <div class="flex items-start justify-between gap-2">
          <p class="min-w-0 truncate font-medium text-neutral-900">{{ row.goal }}</p>
          <BaseBadge :variant="coachingStatusVariant(row.status)">{{ t(`proDev.coachingStatuses.${row.status}`) }}</BaseBadge>
        </div>
        <p class="mt-1 text-sm text-neutral-700">{{ row.staff?.name }} ← {{ row.coach?.name }}</p>
        <p class="mt-0.5 text-xs text-neutral-500">
          {{ t(`proDev.coachingTypes.${row.type}`) }} · {{ formatDate(row.start_date) }} · {{ t('proDev.coaching.sessionsCount', { n: row.sessions_count }) }}
        </p>
      </button>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CoachingFormModal v-model="formOpen" :coaching="editing" :staff="staff" @saved="onSaved" />
    <CoachingDetailModal v-model="detailOpen" :coaching-id="detailId" @changed="refresh" @edit="openForm" />
  </div>
</template>

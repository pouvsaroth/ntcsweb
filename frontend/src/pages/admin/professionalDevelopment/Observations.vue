<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CoachingFormModal from '@/components/admin/CoachingFormModal.vue'
import ObservationDetailModal from '@/components/admin/ObservationDetailModal.vue'
import ObservationFormEditorModal from '@/components/admin/ObservationFormEditorModal.vue'
import ObservationScheduleModal from '@/components/admin/ObservationScheduleModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  OBSERVATION_STATUSES,
  observationsService,
  trainingsService,
  type CoachingInput,
  type Observation,
  type ObservationForm,
  type ObservationMine,
  type ObservationOptions,
  type ObservationSummary,
  type PdStaffOption,
} from '@/services/professionalDevelopment'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { observationStatusVariant } from '@/utils/professionalDevelopment'

/**
 * HRM > Professional Development > Classroom observation. Managers see and
 * schedule every observation and keep the observation forms; everyone else
 * sees the observations of them or by them.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canViewAll = computed(() => auth.can('professional-development.view'))
const canManage = computed(() => auth.can('professional-development.manage'))

const view = ref<'observations' | 'forms'>('observations')
const mine = ref<ObservationMine>('')
const status = ref('')
const awaiting = ref(false)

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Observation>((query) =>
  observationsService.list({ ...query, status: status.value || undefined, mine: mine.value, awaiting: awaiting.value }),
)

const summary = ref<ObservationSummary | null>(null)
async function loadSummary() {
  if (!canViewAll.value) return
  summary.value = await observationsService.summary().catch(() => null)
}
const cards = computed(() => [
  { key: 'scheduled', label: t('proDev.observations.cards.scheduled'), value: summary.value?.scheduled ?? 0, tone: 'text-primary-700' },
  { key: 'completed', label: t('proDev.observations.cards.completedThisYear'), value: summary.value?.completed_this_year ?? 0, tone: 'text-green-700' },
  { key: 'ack', label: t('proDev.observations.cards.awaitingAck'), value: summary.value?.awaiting_acknowledgement ?? 0, tone: 'text-amber-700' },
  { key: 'avg', label: t('proDev.observations.cards.average'), value: summary.value?.average_this_year != null ? `★ ${summary.value.average_this_year}` : '—', tone: 'text-amber-600' },
])

function refresh() {
  void fetch()
  void loadSummary()
}

const mineOptions = computed(() => [
  ...(canViewAll.value ? [{ value: '' as const, label: t('proDev.all') }] : [{ value: '' as const, label: t('proDev.observations.mineAll') }]),
  { value: 'observed' as const, label: t('proDev.observations.mineObserved') },
  { value: 'observer' as const, label: t('proDev.observations.mineObserver') },
])
const statusOptions = computed(() => [{ value: '', label: t('proDev.allStatuses') }, ...OBSERVATION_STATUSES.map((s) => ({ value: s, label: t(`proDev.observationStatuses.${s}`) }))])

function setMine(v: ObservationMine) {
  mine.value = v
  setPage(1)
}

const columns = computed(() => [
  { key: 'date', label: t('proDev.observations.date') },
  { key: 'teacher', label: t('proDev.observations.teacher') },
  { key: 'class', label: t('proDev.observations.class') },
  { key: 'observer', label: t('proDev.observations.observer') },
  { key: 'rating', label: t('proDev.observations.rating') },
  { key: 'status', label: t('proDev.trainings.status') },
])

// --- Forms ------------------------------------------------------------------
const forms = ref<ObservationForm[]>([])
const formsLoading = ref(false)
const formsError = ref<string | null>(null)
async function loadForms() {
  formsLoading.value = true
  formsError.value = null
  try {
    forms.value = await observationsService.forms()
  } catch (e) {
    formsError.value = e instanceof ApiRequestError ? e.message : t('proDev.loadFailed')
  } finally {
    formsLoading.value = false
  }
}
function showForms() {
  view.value = 'forms'
  void loadForms()
}
const editorOpen = ref(false)
const editingForm = ref<ObservationForm | null>(null)
function openForm(f: ObservationForm | null) {
  editingForm.value = f
  editorOpen.value = true
}
async function removeForm(f: ObservationForm) {
  if (!(await confirmDialog.confirm({ message: t('proDev.forms.deleteConfirm', { name: f.name }), danger: true }))) return
  try {
    await observationsService.removeForm(f.id)
    await loadForms()
  } catch (e) {
    formsError.value = e instanceof ApiRequestError ? e.message : t('proDev.deleteFailed')
  }
}

// --- Popups -----------------------------------------------------------------
const options = ref<ObservationOptions | null>(null)
async function ensureOptions() {
  if (!options.value) options.value = await observationsService.options().catch(() => null)
}

const scheduleOpen = ref(false)
const editing = ref<Observation | null>(null)
async function openSchedule(o: Observation | null) {
  await ensureOptions()
  editing.value = o
  detailOpen.value = false
  scheduleOpen.value = true
}

const detailOpen = ref(false)
const detailId = ref<number | null>(null)
function openDetail(o: Observation) {
  detailId.value = o.id
  detailOpen.value = true
}
function onScheduled(o: Observation) {
  refresh()
  if (!editing.value) openDetail(o)
}

// "Start coaching" from a completed observation.
const coachingOpen = ref(false)
const coachingPreset = ref<Partial<CoachingInput> | null>(null)
const staff = ref<PdStaffOption[]>([])
async function startCoaching(o: Observation) {
  if (staff.value.length === 0) staff.value = await trainingsService.staffOptions().catch(() => [])
  coachingPreset.value = { staff_id: o.staff_id, classroom_observation_id: o.id, goal: (o.next_steps ?? o.improvements ?? '').split('\n')[0]!.slice(0, 500) }
  detailOpen.value = false
  coachingOpen.value = true
}

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
      <div v-if="canViewAll" class="inline-flex rounded-lg border border-neutral-300 bg-white p-0.5 shadow-sm" role="group">
        <button
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="view === 'observations' ? 'bg-primary-500 text-secondary-900' : 'text-neutral-600 hover:bg-neutral-100'"
          @click="view = 'observations'"
        >
          {{ t('proDev.observations.list') }}
        </button>
        <button
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="view === 'forms' ? 'bg-primary-500 text-secondary-900' : 'text-neutral-600 hover:bg-neutral-100'"
          @click="showForms"
        >
          {{ t('proDev.forms.title') }}
        </button>
      </div>

      <template v-if="view === 'observations'">
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
        <select
          v-model="status"
          class="rounded-lg border border-neutral-300 py-2 pl-3 pr-9 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
          :aria-label="t('proDev.trainings.status')"
          @change="setPage(1)"
        >
          <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
        <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="awaiting" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-200" @change="setPage(1)" />
          {{ t('proDev.observations.awaitingAck') }}
        </label>
        <input
          type="search"
          :placeholder="t('proDev.observations.searchPlaceholder')"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
          @input="setSearch(($event.target as HTMLInputElement).value)"
        />
        <BaseButton v-if="canManage" class="sm:ml-auto" @click="openSchedule(null)">{{ t('proDev.observations.add') }}</BaseButton>
      </template>
      <BaseButton v-else-if="canManage" class="sm:ml-auto" @click="openForm(null)">{{ t('proDev.forms.add') }}</BaseButton>
    </div>

    <!-- Observations -->
    <template v-if="view === 'observations'">
      <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

      <div class="hidden sm:block">
        <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('proDev.observations.empty')">
          <template #cell-date="{ row }">{{ formatDate(row.observed_on) }}<span v-if="row.start_time" class="block text-xs text-neutral-500">{{ row.start_time }}</span></template>
          <template #cell-teacher="{ row }">
            <button type="button" class="text-left font-medium text-neutral-900 hover:underline" @click="openDetail(row)">{{ row.teacher?.name ?? '—' }}</button>
            <span v-if="row.focus" class="block max-w-xs truncate text-xs text-neutral-500">{{ row.focus }}</span>
          </template>
          <template #cell-class="{ row }">{{ row.class_name ?? '—' }}<span v-if="row.subject" class="block text-xs text-neutral-500">{{ row.subject }}</span></template>
          <template #cell-observer="{ row }">{{ row.observer?.name ?? '—' }}</template>
          <template #cell-rating="{ row }">
            <span v-if="row.average_rating != null" class="font-medium text-amber-600">★ {{ row.average_rating }}</span>
            <span v-else class="text-neutral-400">—</span>
          </template>
          <template #cell-status="{ row }">
            <BaseBadge :variant="observationStatusVariant(row.status)">{{ t(`proDev.observationStatuses.${row.status}`) }}</BaseBadge>
            <BaseBadge v-if="row.status === 'completed' && !row.acknowledged_at" variant="warning" class="ml-1">{{ t('proDev.observations.awaitingAckShort') }}</BaseBadge>
          </template>
        </DataTable>
      </div>

      <div class="space-y-2 sm:hidden">
        <p v-if="loading && items.length === 0" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
        <p v-else-if="items.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('proDev.observations.empty') }}</p>
        <button v-for="row in items" :key="row.id" type="button" class="block w-full rounded-lg border border-neutral-200 bg-white p-3 text-left shadow-sm" @click="openDetail(row)">
          <div class="flex items-start justify-between gap-2">
            <p class="min-w-0 truncate font-medium text-neutral-900">{{ row.teacher?.name }}</p>
            <BaseBadge :variant="observationStatusVariant(row.status)">{{ t(`proDev.observationStatuses.${row.status}`) }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm text-neutral-700">
            {{ formatDate(row.observed_on) }}<template v-if="row.class_name"> · {{ row.class_name }}</template><template v-if="row.subject"> · {{ row.subject }}</template>
          </p>
          <p class="mt-0.5 text-xs text-neutral-500">
            <template v-if="row.observer">{{ t('proDev.observations.by', { name: row.observer.name }) }}</template>
            <template v-if="row.average_rating != null"> · ★ {{ row.average_rating }}</template>
            <template v-if="row.status === 'completed' && !row.acknowledged_at"> · {{ t('proDev.observations.awaitingAckShort') }}</template>
          </p>
        </button>
      </div>

      <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
    </template>

    <!-- Observation forms -->
    <template v-else>
      <BaseAlert v-if="formsError" variant="danger" class="mb-4">{{ formsError }}</BaseAlert>
      <p v-if="formsLoading && forms.length === 0" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="forms.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('proDev.forms.empty') }}</p>
      <div v-else class="grid gap-3 md:grid-cols-2">
        <div v-for="f in forms" :key="f.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-neutral-900">{{ f.name }}</p>
              <p v-if="f.description" class="mt-0.5 text-sm text-neutral-600">{{ f.description }}</p>
            </div>
            <BaseBadge :variant="f.is_active ? 'success' : 'neutral'">{{ f.is_active ? t('proDev.forms.activeBadge') : t('proDev.forms.inactive') }}</BaseBadge>
          </div>
          <p class="mt-2 text-xs text-neutral-500">{{ t('proDev.forms.criteriaCount', { n: f.criteria.length }) }}</p>
          <ul class="mt-1 list-inside list-disc text-sm text-neutral-700">
            <li v-for="c in f.criteria.slice(0, 4)" :key="c.id" class="truncate">{{ c.criterion }}</li>
            <li v-if="f.criteria.length > 4" class="list-none text-xs text-neutral-500">{{ t('proDev.more', { count: f.criteria.length - 4 }) }}</li>
          </ul>
          <div v-if="canManage" class="mt-3 flex gap-3 text-sm">
            <button type="button" class="font-medium text-secondary-700 hover:underline" @click="openForm(f)">{{ t('proDev.edit') }}</button>
            <button type="button" class="font-medium text-danger-600 hover:text-red-700" @click="removeForm(f)">{{ t('proDev.delete') }}</button>
          </div>
        </div>
      </div>
    </template>

    <ObservationScheduleModal v-model="scheduleOpen" :observation="editing" :options="options" @saved="onScheduled" />
    <ObservationDetailModal v-model="detailOpen" :observation-id="detailId" @changed="refresh" @edit="openSchedule" @coach="startCoaching" />
    <ObservationFormEditorModal v-model="editorOpen" :form="editingForm" @saved="loadForms(); options = null" />
    <CoachingFormModal v-model="coachingOpen" :coaching="null" :staff="staff" :preset="coachingPreset" />
  </div>
</template>

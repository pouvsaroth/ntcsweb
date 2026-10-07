<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import CycleReviews from '@/pages/admin/performance/CycleReviews.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  evaluationFormsService,
  performanceCyclesService,
  type CycleStatus,
  type EvaluationForm,
  type PerformanceCycle,
  type PerformanceWeights,
} from '@/services/performance'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Performance Management > Performance review — how the final score
 * is weighted, review cycles (the period, the evaluation form, when each
 * assessment is due), and each cycle's reviews (see CycleReviews). Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('performance.manage'))

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<PerformanceCycle>((query) => performanceCyclesService.list(query))
const actionError = ref<string | null>(null)
const reviews = ref<InstanceType<typeof CycleReviews> | null>(null)
const statusVariant: Record<CycleStatus, 'neutral' | 'primary' | 'success'> = { draft: 'neutral', active: 'primary', closed: 'success' }

// --- Weights ---------------------------------------------------------------------------------

const weights = ref<PerformanceWeights | null>(null)
const weightsOpen = ref(false)
const weightForm = reactive({ kpi_weight: '', goal_weight: '', manager_weight: '' })
const weightError = ref<string | null>(null)
const weightTotal = computed(() => Number(weightForm.kpi_weight || 0) + Number(weightForm.goal_weight || 0) + Number(weightForm.manager_weight || 0))

function openWeights() {
  if (!weights.value) return
  weightForm.kpi_weight = String(weights.value.kpi_weight)
  weightForm.goal_weight = String(weights.value.goal_weight)
  weightForm.manager_weight = String(weights.value.manager_weight)
  weightError.value = null
  weightsOpen.value = true
}

async function saveWeights() {
  weightError.value = null
  try {
    weights.value = await performanceCyclesService.updateWeights({
      kpi_weight: Number(weightForm.kpi_weight || 0),
      goal_weight: Number(weightForm.goal_weight || 0),
      manager_weight: Number(weightForm.manager_weight || 0),
    })
    weightsOpen.value = false
  } catch (e) {
    weightError.value = e instanceof ApiRequestError ? (e.errors?.kpi_weight?.[0] ?? e.message) : t('admin.performance.saveFailed')
  }
}

// --- Cycle form ------------------------------------------------------------------------------------

const forms = ref<EvaluationForm[]>([])
const formOptions = computed(() => [{ value: '', label: t('admin.performance.cycles.noForm') }, ...forms.value.filter((f) => f.is_active).map((f) => ({ value: String(f.id), label: f.name }))])
const statusOptions = computed(() => (['draft', 'active', 'closed'] as CycleStatus[]).map((s) => ({ value: s, label: t(`admin.performance.cycles.status.${s}`) })))

const formOpen = ref(false)
const editing = ref<PerformanceCycle | null>(null)
const form = reactive({ name: '', start_date: '', end_date: '', self_assessment_due: '', manager_assessment_due: '', evaluation_form_id: '', status: 'draft' as CycleStatus, description: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

async function open(cycle: PerformanceCycle | null) {
  const year = new Date().getFullYear()
  editing.value = cycle
  form.name = cycle?.name ?? `${year}`
  form.start_date = cycle?.start_date ?? `${year}-01-01`
  form.end_date = cycle?.end_date ?? `${year}-12-31`
  form.self_assessment_due = cycle?.self_assessment_due ?? ''
  form.manager_assessment_due = cycle?.manager_assessment_due ?? ''
  form.evaluation_form_id = cycle?.evaluation_form ? String(cycle.evaluation_form.id) : ''
  form.status = cycle?.status ?? 'draft'
  form.description = cycle?.description ?? ''
  errors.value = {}
  saveError.value = null
  formOpen.value = true
  if (forms.value.length === 0) forms.value = await evaluationFormsService.listAll().catch(() => [])
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    name: form.name,
    start_date: form.start_date,
    end_date: form.end_date,
    self_assessment_due: form.self_assessment_due || null,
    manager_assessment_due: form.manager_assessment_due || null,
    evaluation_form_id: form.evaluation_form_id ? Number(form.evaluation_form_id) : null,
    status: form.status,
    description: form.description.trim() || null,
  }
  try {
    if (editing.value) await performanceCyclesService.update(editing.value.id, input)
    else await performanceCyclesService.create(input)
    formOpen.value = false
    await fetch()
    await reviews.value?.loadCycles()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.performance.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(cycle: PerformanceCycle) {
  if (!(await confirmDialog.confirm({ message: t('admin.performance.deleteConfirm', { name: cycle.name }), danger: true }))) return
  actionError.value = null
  try {
    await performanceCyclesService.remove(cycle.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(async () => {
  void fetch()
  weights.value = await performanceCyclesService.weights().catch(() => null)
})
</script>

<template>
  <div class="space-y-6">
    <!-- Score weights -->
    <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
      <div class="mb-3 flex items-start justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.performance.weights.title') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('admin.performance.weights.hint') }}</p>
        </div>
        <BaseButton v-if="canManage && weights" size="sm" variant="outline" @click="openWeights">{{ t('admin.performance.edit') }}</BaseButton>
      </div>
      <dl v-if="weights" class="grid grid-cols-3 gap-3 text-sm">
        <div><dt class="text-neutral-500">{{ t('admin.performance.tabs.kpi') }}</dt><dd class="text-lg font-semibold">{{ weights.kpi_weight }}%</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.performance.tabs.goals') }}</dt><dd class="text-lg font-semibold">{{ weights.goal_weight }}%</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.performance.weights.manager') }}</dt><dd class="text-lg font-semibold">{{ weights.manager_weight }}%</dd></div>
      </dl>
    </section>

    <!-- Cycles -->
    <section>
      <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.performance.cycles.title') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('admin.performance.cycles.hint') }}</p>
        </div>
        <BaseButton v-if="canManage" @click="open(null)">{{ t('admin.performance.cycles.add') }}</BaseButton>
      </div>

      <BaseAlert v-if="error || actionError" variant="danger" class="mb-3">{{ error || actionError }}</BaseAlert>
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.performance.cycles.empty') }}</p>
      <template v-else>
        <div class="space-y-2 sm:hidden">
          <div v-for="cycle in items" :key="cycle.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <p class="text-sm font-semibold text-neutral-800">{{ cycle.name }}</p>
              <BaseBadge :variant="statusVariant[cycle.status]">{{ t(`admin.performance.cycles.status.${cycle.status}`) }}</BaseBadge>
            </div>
            <p class="text-xs text-neutral-600">{{ formatDate(cycle.start_date) }} – {{ formatDate(cycle.end_date) }}</p>
            <p class="text-xs text-neutral-500">{{ cycle.evaluation_form?.name ?? t('admin.performance.cycles.noForm') }} · {{ t('admin.performance.cycles.goalsN', { count: cycle.goals_count ?? 0 }) }}</p>
            <div v-if="canManage" class="mt-2 flex justify-end gap-3">
              <EditIconButton @click="open(cycle)" />
              <button v-if="cycle.status === 'draft'" type="button" class="text-sm font-medium text-danger-600" @click="remove(cycle)">{{ t('admin.organization.delete') }}</button>
            </div>
          </div>
        </div>
        <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-4 py-3 font-medium">{{ t('admin.performance.cycles.name') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.performance.cycles.period') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.performance.cycles.form') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.performance.cycles.due') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.performance.tabs.goals') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.organization.status') }}</th>
                <th v-if="canManage" class="px-4 py-3 text-right font-medium">{{ t('admin.organization.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="cycle in items" :key="cycle.id">
                <td class="px-4 py-3 font-medium text-neutral-800">{{ cycle.name }}</td>
                <td class="px-4 py-3 text-neutral-700">{{ formatDate(cycle.start_date) }} – {{ formatDate(cycle.end_date) }}</td>
                <td class="px-4 py-3 text-neutral-700">{{ cycle.evaluation_form?.name ?? '—' }}</td>
                <td class="px-4 py-3 text-xs text-neutral-600">
                  <p>{{ t('admin.performance.cycles.selfDue') }}: {{ cycle.self_assessment_due ? formatDate(cycle.self_assessment_due) : '—' }}</p>
                  <p>{{ t('admin.performance.cycles.managerDue') }}: {{ cycle.manager_assessment_due ? formatDate(cycle.manager_assessment_due) : '—' }}</p>
                </td>
                <td class="px-4 py-3 text-right tabular-nums">{{ cycle.goals_count ?? 0 }}</td>
                <td class="px-4 py-3"><BaseBadge :variant="statusVariant[cycle.status]">{{ t(`admin.performance.cycles.status.${cycle.status}`) }}</BaseBadge></td>
                <td v-if="canManage" class="px-4 py-3">
                  <div class="flex justify-end gap-2">
                    <EditIconButton @click="open(cycle)" />
                    <button v-if="cycle.status === 'draft'" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(cycle)">{{ t('admin.organization.delete') }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
      <BasePagination v-if="meta" :meta="meta" class="mt-3" @update:page="setPage" />
    </section>

    <CycleReviews ref="reviews" />

    <BaseModal v-model="formOpen" :title="editing ? t('admin.performance.cycles.edit') : t('admin.performance.cycles.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <BaseInput v-model="form.name" required :label="t('admin.performance.cycles.name')" :placeholder="t('admin.performance.cycles.namePlaceholder')" :error="errors.name?.[0]" />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.start_date" type="date" required :label="t('admin.performance.cycles.start')" :error="errors.start_date?.[0]" />
          <BaseInput v-model="form.end_date" type="date" required :label="t('admin.performance.cycles.end')" :error="errors.end_date?.[0]" />
          <BaseInput v-model="form.self_assessment_due" type="date" :label="t('admin.performance.cycles.selfDue')" :error="errors.self_assessment_due?.[0]" />
          <BaseInput v-model="form.manager_assessment_due" type="date" :label="t('admin.performance.cycles.managerDue')" :error="errors.manager_assessment_due?.[0]" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.evaluation_form_id" :options="formOptions" :label="t('admin.performance.cycles.form')" :error="errors.evaluation_form_id?.[0]" />
          <BaseSelect :model-value="form.status" :options="statusOptions" :label="t('admin.organization.status')" @update:model-value="form.status = $event as CycleStatus" />
        </div>
        <BaseInput v-model="form.description" :label="t('admin.organization.description')" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="weightsOpen" :title="t('admin.performance.weights.title')">
      <BaseAlert v-if="weightError" variant="danger" class="mb-3">{{ weightError }}</BaseAlert>
      <div class="grid gap-4 sm:grid-cols-3">
        <BaseInput v-model="weightForm.kpi_weight" type="number" :label="`${t('admin.performance.tabs.kpi')} (%)`" />
        <BaseInput v-model="weightForm.goal_weight" type="number" :label="`${t('admin.performance.tabs.goals')} (%)`" />
        <BaseInput v-model="weightForm.manager_weight" type="number" :label="`${t('admin.performance.weights.manager')} (%)`" />
      </div>
      <p class="mt-3 text-sm" :class="weightTotal === 100 ? 'text-neutral-600' : 'text-red-700'">{{ t('admin.performance.weights.total', { total: weightTotal }) }}</p>
      <template #footer>
        <BaseButton variant="outline" @click="weightsOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :disabled="weightTotal !== 100" @click="saveWeights">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

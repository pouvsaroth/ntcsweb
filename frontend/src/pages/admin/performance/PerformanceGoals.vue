<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
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
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { performanceCyclesService, performanceGoalsService, type GoalStatus, type PerformanceCycle, type PerformanceGoal } from '@/services/performance'
import { staffService, type Staff } from '@/services/staff'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Performance Management > Goals — each staff member's goals for a
 * review cycle (or open-ended), with progress. Rated 1–5 by the staff
 * member and their manager during the review; `weight` is a goal's share of
 * the goals part of the score. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('performance.manage'))

const cycles = ref<PerformanceCycle[]>([])
const staff = ref<Staff[]>([])
const cycleFilter = ref('')
const statusFilter = ref('')

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<PerformanceGoal>((query) => performanceGoalsService.list(query))
watch(cycleFilter, (value) => setFilter('performance_cycle_id', value || undefined))
watch(statusFilter, (value) => setFilter('status', value || undefined))

const statuses: GoalStatus[] = ['not_started', 'in_progress', 'completed', 'cancelled']
const statusVariant: Record<GoalStatus, 'neutral' | 'primary' | 'success' | 'danger'> = { not_started: 'neutral', in_progress: 'primary', completed: 'success', cancelled: 'danger' }
const statusOptions = computed(() => statuses.map((s) => ({ value: s, label: t(`admin.performance.goals.status.${s}`) })))
const cycleOptions = computed(() => cycles.value.map((c) => ({ value: String(c.id), label: c.name })))
const staffOptions = computed(() => staff.value.map((s) => ({ value: String(s.id), label: s.full_name, hint: s.employee_code })))
const actionError = ref<string | null>(null)

// --- Form ------------------------------------------------------------------------------------

const formOpen = ref(false)
const editing = ref<PerformanceGoal | null>(null)
const form = reactive({ staff_id: '', performance_cycle_id: '', title: '', description: '', due_date: '', weight: '0', progress: '0', status: 'not_started' as GoalStatus })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

async function open(goal: PerformanceGoal | null) {
  editing.value = goal
  form.staff_id = goal?.staff ? String(goal.staff.id) : ''
  form.performance_cycle_id = goal?.cycle ? String(goal.cycle.id) : cycleFilter.value
  form.title = goal?.title ?? ''
  form.description = goal?.description ?? ''
  form.due_date = goal?.due_date ?? ''
  form.weight = String(goal?.weight ?? 0)
  form.progress = String(goal?.progress ?? 0)
  form.status = goal?.status ?? 'not_started'
  errors.value = {}
  saveError.value = null
  formOpen.value = true
  if (staff.value.length === 0) staff.value = await staffService.listAll().catch(() => [])
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    performance_cycle_id: form.performance_cycle_id ? Number(form.performance_cycle_id) : null,
    title: form.title,
    description: form.description.trim() || null,
    due_date: form.due_date || null,
    weight: Number(form.weight || 0),
    progress: Number(form.progress || 0),
    status: form.status,
  }
  try {
    if (editing.value) await performanceGoalsService.update(editing.value.id, input)
    else await performanceGoalsService.create({ ...input, staff_id: Number(form.staff_id) })
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.performance.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(goal: PerformanceGoal) {
  if (!(await confirmDialog.confirm({ message: t('admin.performance.deleteConfirm', { name: goal.title }), danger: true }))) return
  actionError.value = null
  try {
    await performanceGoalsService.remove(goal.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(async () => {
  void fetch()
  cycles.value = await performanceCyclesService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <BaseSelect v-model="cycleFilter" class="w-full sm:w-56" :options="[{ value: '', label: t('admin.performance.goals.allCycles') }, ...cycleOptions]" />
      <BaseSelect v-model="statusFilter" class="w-full sm:w-44" :options="[{ value: '', label: t('admin.performance.goals.allStatuses') }, ...statusOptions]" />
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="canManage" class="sm:ml-auto" @click="open(null)">{{ t('admin.performance.goals.add') }}</BaseButton>
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.performance.goals.hint') }}</p>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.performance.goals.empty') }}</p>
    <template v-else>
      <div class="space-y-2 sm:hidden">
        <div v-for="goal in items" :key="goal.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ goal.title }}</p>
            <BaseBadge :variant="statusVariant[goal.status]">{{ t(`admin.performance.goals.status.${goal.status}`) }}</BaseBadge>
          </div>
          <p class="text-xs text-neutral-600">{{ goal.staff?.name }} ({{ goal.staff?.employee_code }})<template v-if="goal.cycle"> · {{ goal.cycle.name }}</template></p>
          <div class="mt-2 h-2 overflow-hidden rounded-full bg-neutral-100"><div class="h-full bg-primary-500" :style="{ width: `${goal.progress}%` }" /></div>
          <p class="mt-1 text-xs text-neutral-500">{{ goal.progress }}%<template v-if="goal.due_date"> · {{ t('admin.performance.goals.dueOn', { date: formatDate(goal.due_date) }) }}</template></p>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="open(goal)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(goal)">{{ t('admin.organization.delete') }}</button>
          </div>
        </div>
      </div>
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-3 font-medium">{{ t('admin.performance.goals.goal') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.performance.staff') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.performance.goals.cycle') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.performance.goals.progress') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.performance.goals.due') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.organization.status') }}</th>
              <th v-if="canManage" class="px-4 py-3 text-right font-medium">{{ t('admin.organization.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="goal in items" :key="goal.id">
              <td class="px-4 py-3">
                <p class="font-medium text-neutral-800">{{ goal.title }}</p>
                <p v-if="goal.weight" class="text-xs text-neutral-500">{{ t('admin.performance.goals.weightN', { weight: goal.weight }) }}</p>
              </td>
              <td class="px-4 py-3">
                <p class="text-neutral-800">{{ goal.staff?.name }}</p>
                <p class="text-xs text-neutral-500">{{ goal.staff?.employee_code }}</p>
              </td>
              <td class="px-4 py-3 text-neutral-700">{{ goal.cycle?.name ?? '—' }}</td>
              <td class="w-40 px-4 py-3">
                <div class="h-2 overflow-hidden rounded-full bg-neutral-100"><div class="h-full bg-primary-500" :style="{ width: `${goal.progress}%` }" /></div>
                <p class="mt-0.5 text-xs text-neutral-500">{{ goal.progress }}%</p>
              </td>
              <td class="px-4 py-3 text-neutral-700">{{ goal.due_date ? formatDate(goal.due_date) : '—' }}</td>
              <td class="px-4 py-3"><BaseBadge :variant="statusVariant[goal.status]">{{ t(`admin.performance.goals.status.${goal.status}`) }}</BaseBadge></td>
              <td v-if="canManage" class="px-4 py-3">
                <div class="flex justify-end gap-2">
                  <EditIconButton @click="open(goal)" />
                  <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(goal)">{{ t('admin.organization.delete') }}</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal v-model="formOpen" :title="editing ? t('admin.performance.goals.edit') : t('admin.performance.goals.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <SearchableSelect v-if="!editing" v-model="form.staff_id" required :options="staffOptions" :label="t('admin.performance.staff')" :error="errors.staff_id?.[0]" />
        <p v-else class="text-sm text-neutral-700"><span class="text-neutral-500">{{ t('admin.performance.staff') }}:</span> {{ editing.staff?.name }}</p>
        <BaseInput v-model="form.title" required :label="t('admin.performance.goals.goal')" :error="errors.title?.[0]" />
        <BaseInput v-model="form.description" :label="t('admin.organization.description')" />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.performance_cycle_id" :options="[{ value: '', label: t('admin.performance.goals.noCycle') }, ...cycleOptions]" :label="t('admin.performance.goals.cycle')" :error="errors.performance_cycle_id?.[0]" />
          <BaseInput v-model="form.due_date" type="date" :label="t('admin.performance.goals.due')" :error="errors.due_date?.[0]" />
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
          <BaseSelect :model-value="form.status" :options="statusOptions" :label="t('admin.organization.status')" @update:model-value="form.status = $event as GoalStatus" />
          <BaseInput v-model="form.progress" type="number" :label="t('admin.performance.goals.progressLabel')" :error="errors.progress?.[0]" />
          <BaseInput v-model="form.weight" type="number" :label="t('admin.performance.goals.weightLabel')" :hint="t('admin.performance.goals.weightHint')" :error="errors.weight?.[0]" />
        </div>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

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
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { departmentsService, type Department } from '@/services/departments'
import { kpisService, type Kpi } from '@/services/performance'
import { positionsService, type Position } from '@/services/positions'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Performance Management > KPI — the KPI library: what's measured, how,
 * its unit and target, and who it's for (everyone, a department or a
 * position). A review takes these with its own targets and weights. Cards
 * on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('performance.manage'))

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Kpi>((query) => kpisService.list(query))
const actionError = ref<string | null>(null)

function targetLabel(kpi: Kpi): string {
  if (kpi.target === null) return '—'
  return `${kpi.higher_is_better ? '≥' : '≤'} ${Number(kpi.target).toLocaleString()}${kpi.unit ? ` ${kpi.unit}` : ''}`
}

function forWhom(kpi: Kpi): string {
  return [kpi.department?.name, kpi.position?.name].filter(Boolean).join(' · ') || t('admin.performance.kpis.everyone')
}

// --- Form ------------------------------------------------------------------------------------

const departments = ref<Department[]>([])
const positions = ref<Position[]>([])
const departmentOptions = computed(() => [{ value: '', label: t('admin.performance.kpis.everyone') }, ...departments.value.map((d) => ({ value: String(d.id), label: d.name }))])
const positionOptions = computed(() => [{ value: '', label: t('admin.performance.kpis.anyPosition') }, ...positions.value.map((p) => ({ value: String(p.id), label: p.name }))])
const directionOptions = computed(() => [
  { value: '1', label: t('admin.performance.kpis.higherBetter') },
  { value: '0', label: t('admin.performance.kpis.lowerBetter') },
])

const formOpen = ref(false)
const editing = ref<Kpi | null>(null)
const form = reactive({ code: '', name: '', description: '', measurement: '', unit: '', target: '', higher_is_better: '1', default_weight: '0', department_id: '', position_id: '', is_active: true })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

async function open(kpi: Kpi | null) {
  editing.value = kpi
  form.code = kpi?.code ?? ''
  form.name = kpi?.name ?? ''
  form.description = kpi?.description ?? ''
  form.measurement = kpi?.measurement ?? ''
  form.unit = kpi?.unit ?? ''
  form.target = kpi?.target === null || kpi === null ? '' : String(kpi.target)
  form.higher_is_better = kpi?.higher_is_better === false ? '0' : '1'
  form.default_weight = String(kpi?.default_weight ?? 0)
  form.department_id = kpi?.department ? String(kpi.department.id) : ''
  form.position_id = kpi?.position ? String(kpi.position.id) : ''
  form.is_active = kpi?.is_active ?? true
  errors.value = {}
  saveError.value = null
  formOpen.value = true
  if (departments.value.length === 0) departments.value = await departmentsService.listAll().catch(() => [])
  if (positions.value.length === 0) positions.value = await positionsService.listAll().catch(() => [])
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    code: form.code,
    name: form.name,
    description: form.description.trim() || null,
    measurement: form.measurement.trim() || null,
    unit: form.unit.trim() || null,
    target: form.target === '' ? null : Number(form.target),
    higher_is_better: form.higher_is_better === '1',
    default_weight: Number(form.default_weight || 0),
    department_id: form.department_id ? Number(form.department_id) : null,
    position_id: form.position_id ? Number(form.position_id) : null,
    is_active: form.is_active,
  }
  try {
    if (editing.value) await kpisService.update(editing.value.id, input)
    else await kpisService.create(input)
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.performance.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(kpi: Kpi) {
  if (!(await confirmDialog.confirm({ message: t('admin.performance.deleteConfirm', { name: kpi.name }), danger: true }))) return
  actionError.value = null
  try {
    await kpisService.remove(kpi.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="canManage" @click="open(null)">{{ t('admin.performance.kpis.add') }}</BaseButton>
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.performance.kpis.hint') }}</p>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.performance.kpis.empty') }}</p>
    <template v-else>
      <div class="space-y-2 sm:hidden">
        <div v-for="kpi in items" :key="kpi.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ kpi.name }} <span class="font-normal text-neutral-500">({{ kpi.code }})</span></p>
            <BaseBadge :variant="kpi.is_active ? 'success' : 'neutral'">{{ kpi.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
          </div>
          <p class="text-sm text-neutral-700">{{ t('admin.performance.kpis.target') }}: {{ targetLabel(kpi) }}</p>
          <p class="text-xs text-neutral-500">{{ forWhom(kpi) }}<template v-if="kpi.measurement"> · {{ kpi.measurement }}</template></p>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="open(kpi)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(kpi)">{{ t('admin.organization.delete') }}</button>
          </div>
        </div>
      </div>
      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-3 font-medium">{{ t('admin.performance.kpis.name') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.performance.kpis.measurement') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.performance.kpis.target') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.performance.kpis.weight') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.performance.kpis.forWhom') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.organization.status') }}</th>
              <th v-if="canManage" class="px-4 py-3 text-right font-medium">{{ t('admin.organization.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="kpi in items" :key="kpi.id">
              <td class="px-4 py-3">
                <p class="font-medium text-neutral-800">{{ kpi.name }}</p>
                <p class="text-xs text-neutral-500">{{ kpi.code }}</p>
              </td>
              <td class="px-4 py-3 text-neutral-700">{{ kpi.measurement ?? '—' }}</td>
              <td class="px-4 py-3 text-neutral-700">{{ targetLabel(kpi) }}</td>
              <td class="px-4 py-3 text-right tabular-nums">{{ kpi.default_weight }}%</td>
              <td class="px-4 py-3 text-neutral-700">{{ forWhom(kpi) }}</td>
              <td class="px-4 py-3"><BaseBadge :variant="kpi.is_active ? 'success' : 'neutral'">{{ kpi.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge></td>
              <td v-if="canManage" class="px-4 py-3">
                <div class="flex justify-end gap-2">
                  <EditIconButton @click="open(kpi)" />
                  <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(kpi)">{{ t('admin.organization.delete') }}</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal v-model="formOpen" size="lg" :title="editing ? t('admin.performance.kpis.edit') : t('admin.performance.kpis.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-3">
          <BaseInput v-model="form.code" required :label="t('admin.organization.code')" :error="errors.code?.[0]" />
          <BaseInput v-model="form.name" required class="sm:col-span-2" :label="t('admin.performance.kpis.name')" :error="errors.name?.[0]" />
        </div>
        <BaseInput v-model="form.measurement" :label="t('admin.performance.kpis.measurement')" :hint="t('admin.performance.kpis.measurementHint')" :error="errors.measurement?.[0]" />
        <div class="grid gap-4 sm:grid-cols-4">
          <BaseInput v-model="form.target" type="number" :label="t('admin.performance.kpis.target')" :error="errors.target?.[0]" />
          <BaseInput v-model="form.unit" :label="t('admin.performance.kpis.unit')" :placeholder="t('admin.performance.kpis.unitPlaceholder')" :error="errors.unit?.[0]" />
          <BaseSelect v-model="form.higher_is_better" :options="directionOptions" :label="t('admin.performance.kpis.direction')" />
          <BaseInput v-model="form.default_weight" type="number" :label="t('admin.performance.kpis.weightLabel')" :error="errors.default_weight?.[0]" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.department_id" :options="departmentOptions" :label="t('admin.performance.kpis.department')" :error="errors.department_id?.[0]" />
          <BaseSelect v-model="form.position_id" :options="positionOptions" :label="t('admin.performance.kpis.position')" :error="errors.position_id?.[0]" />
        </div>
        <BaseInput v-model="form.description" :label="t('admin.organization.description')" />
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.organization.statusActive') }}
        </label>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

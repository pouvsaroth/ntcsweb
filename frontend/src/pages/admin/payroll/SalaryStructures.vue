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
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  payAmountLabel,
  payComponentsService,
  salaryStructuresService,
  type PayComponent,
  type PayCurrency,
  type SalaryStructure,
} from '@/services/payroll'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Payroll > Salary structure — reusable pay packages ("Full-time
 * teacher") of allowances and deductions with their monthly amounts, in one
 * currency. A staff member's basic salary can follow one. Cards on a phone,
 * a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canManage = computed(() => auth.can('payroll.manage'))

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<SalaryStructure>((query) => salaryStructuresService.list(query))

function itemsSummary(structure: SalaryStructure): string {
  if (structure.items.length === 0) return t('admin.payroll.structures.noItems')
  return structure.items
    .map((item) => `${item.component?.name ?? '—'} ${payAmountLabel(item.amount, item.component?.calculation, structure.currency)}`)
    .join(' · ')
}

const columns = computed(() => [
  { key: 'name', label: t('admin.payroll.structures.name') },
  { key: 'currency', label: t('admin.payroll.currency') },
  { key: 'items', label: t('admin.payroll.structures.items') },
  { key: 'staff', label: t('admin.payroll.structures.staff') },
  { key: 'is_active', label: t('admin.organization.status') },
  ...(canManage.value ? [{ key: 'actions', label: t('admin.organization.actions'), align: 'text-right' }] : []),
])

const currencyOptions = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]

// --- Form ----------------------------------------------------------------------

const components = ref<PayComponent[]>([])
const componentOptions = computed(() =>
  components.value
    .filter((c) => c.kind !== 'bonus' && (c.is_active || form.items.some((item) => item.payroll_component_id === String(c.id))))
    .map((c) => ({ value: String(c.id), label: `${c.name} (${t(`admin.payroll.kinds.${c.kind}`)})` })),
)

function componentOf(id: string): PayComponent | undefined {
  return components.value.find((c) => String(c.id) === id)
}

const formOpen = ref(false)
const editing = ref<SalaryStructure | null>(null)
const form = reactive({
  code: '',
  name: '',
  currency: 'USD' as PayCurrency,
  description: '',
  is_active: true,
  items: [] as { payroll_component_id: string; amount: string }[],
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const actionError = ref<string | null>(null)

async function open(structure: SalaryStructure | null) {
  editing.value = structure
  form.code = structure?.code ?? ''
  form.name = structure?.name ?? ''
  form.currency = structure?.currency ?? 'USD'
  form.description = structure?.description ?? ''
  form.is_active = structure?.is_active ?? true
  form.items = (structure?.items ?? []).map((item) => ({ payroll_component_id: String(item.payroll_component_id), amount: String(Number(item.amount)) }))
  errors.value = {}
  saveError.value = null
  formOpen.value = true
  if (components.value.length === 0) components.value = await payComponentsService.listAll().catch(() => [])
}

function addItem() {
  form.items.push({ payroll_component_id: '', amount: '' })
}

function removeItem(index: number) {
  form.items.splice(index, 1)
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    code: form.code,
    name: form.name,
    currency: form.currency,
    description: form.description.trim() || null,
    is_active: form.is_active,
    items: form.items
      .filter((item) => item.payroll_component_id !== '')
      .map((item) => ({ payroll_component_id: Number(item.payroll_component_id), amount: Number(item.amount || 0) })),
  }
  try {
    if (editing.value) await salaryStructuresService.update(editing.value.id, input)
    else await salaryStructuresService.create(input)
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(structure: SalaryStructure) {
  if (!(await confirmDialog.confirm({ message: t('admin.payroll.structures.deleteConfirm', { name: structure.name }), danger: true }))) return
  actionError.value = null
  try {
    await salaryStructuresService.remove(structure.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-4 flex items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">{{ t('admin.payroll.structures.hint') }}</p>
      <BaseButton v-if="canManage" @click="open(null)">{{ t('admin.payroll.structures.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.payroll.structures.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ row.name }} <span class="font-normal text-neutral-500">({{ row.code }} · {{ row.currency }})</span></p>
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm text-neutral-700">{{ itemsSummary(row) }}</p>
          <p class="text-xs text-neutral-500">{{ t('admin.payroll.structures.staffN', { count: row.salaries_count ?? 0 }) }}</p>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="open(row)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.organization.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.payroll.structures.empty')">
        <template #cell-name="{ row }">
          <p class="font-medium text-neutral-800">{{ row.name }}</p>
          <p class="text-xs text-neutral-500">{{ row.code }}</p>
        </template>
        <template #cell-currency="{ row }">{{ row.currency }}</template>
        <template #cell-items="{ row }"><span class="text-sm text-neutral-700">{{ itemsSummary(row as SalaryStructure) }}</span></template>
        <template #cell-staff="{ row }">{{ row.salaries_count ?? 0 }}</template>
        <template #cell-is_active="{ row }">
          <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="open(row as SalaryStructure)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as SalaryStructure)">{{ t('admin.organization.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal v-model="formOpen" size="lg" :title="editing ? t('admin.payroll.structures.edit') : t('admin.payroll.structures.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-3">
          <BaseInput v-model="form.code" required :label="t('admin.organization.code')" :error="errors.code?.[0]" />
          <BaseInput v-model="form.name" required :label="t('admin.payroll.structures.name')" :error="errors.name?.[0]" />
          <BaseSelect
            :model-value="form.currency"
            :options="currencyOptions"
            required
            :label="t('admin.payroll.currency')"
            :error="errors.currency?.[0]"
            @update:model-value="form.currency = $event as PayCurrency"
          />
        </div>
        <BaseInput v-model="form.description" :label="t('admin.organization.description')" />

        <section>
          <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-neutral-800">{{ t('admin.payroll.structures.items') }}</h3>
            <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="addItem">+ {{ t('admin.payroll.structures.addItem') }}</button>
          </div>
          <p v-if="form.items.length === 0" class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-500">{{ t('admin.payroll.structures.itemsHint') }}</p>
          <div v-for="(item, index) in form.items" :key="index" class="mb-2 grid grid-cols-[minmax(0,1fr)_8rem_auto] items-start gap-2">
            <BaseSelect
              v-model="item.payroll_component_id"
              :options="componentOptions"
              :placeholder="t('admin.payroll.structures.pickComponent')"
              :error="errors[`items.${index}.payroll_component_id`]?.[0]"
            />
            <BaseInput
              v-model="item.amount"
              type="number"
              :placeholder="componentOf(item.payroll_component_id)?.calculation === 'percent_of_basic' ? '%' : form.currency"
              :error="errors[`items.${index}.amount`]?.[0]"
            />
            <button type="button" class="mt-2 text-sm font-medium text-danger-600" :aria-label="t('admin.organization.delete')" @click="removeItem(index)">✕</button>
          </div>
        </section>

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

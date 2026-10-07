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
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  payAmountLabel,
  salaryStructuresService,
  staffSalariesService,
  type PayCurrency,
  type PaymentMethod,
  type PayrollStaff,
  type SalaryStructure,
  type StaffSalary,
  type StaffSalaryRow,
} from '@/services/payroll'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Payroll > Basic salary — each working staff member's monthly basic
 * salary in effect today, in their own currency. A raise is a new salary
 * from its own date, so the history stays (open a staff member to see it).
 * Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canManage = computed(() => auth.can('payroll.manage'))
const missingOnly = ref(false)

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<StaffSalaryRow>((query) =>
  staffSalariesService.list(query, missingOnly.value),
)

// setPage() refetches with the new filter.
watch(missingOnly, () => setPage(1))

function money(salary: StaffSalary): string {
  return payAmountLabel(salary.basic_salary, 'fixed', salary.currency)
}

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

// --- One staff member: history + set a salary -------------------------------------

const structures = ref<SalaryStructure[]>([])
const detailOpen = ref(false)
const detailLoading = ref(false)
const detailStaff = ref<PayrollStaff | null>(null)
const history = ref<StaffSalary[]>([])

async function openDetail(staff: PayrollStaff) {
  detailStaff.value = staff
  detailOpen.value = true
  formOpen.value = false
  await loadHistory()
  if (structures.value.length === 0) structures.value = await salaryStructuresService.listAll().catch(() => [])
}

async function loadHistory() {
  if (!detailStaff.value) return
  detailLoading.value = true
  try {
    history.value = (await staffSalariesService.history(detailStaff.value.id)).salaries
  } finally {
    detailLoading.value = false
  }
}

const formOpen = ref(false)
const editing = ref<StaffSalary | null>(null)
const form = reactive({
  basic_salary: '',
  currency: 'USD' as PayCurrency,
  salary_structure_id: '',
  effective_from: '',
  payment_method: 'bank' as PaymentMethod,
  bank_name: '',
  bank_account_name: '',
  bank_account_number: '',
  note: '',
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const currencyOptions = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]
const paymentOptions = computed(() => [
  { value: 'bank', label: t('admin.payroll.salaries.bank') },
  { value: 'cash', label: t('admin.payroll.salaries.cash') },
])
const structureOptions = computed(() => [
  { value: '', label: t('admin.payroll.salaries.noStructure') },
  ...structures.value
    .filter((s) => s.currency === form.currency && (s.is_active || String(s.id) === form.salary_structure_id))
    .map((s) => ({ value: String(s.id), label: `${s.name} (${s.code})` })),
])

// A structure in the other currency can't be kept once the currency changes.
watch(() => form.currency, () => {
  if (form.salary_structure_id && !structures.value.some((s) => String(s.id) === form.salary_structure_id && s.currency === form.currency)) {
    form.salary_structure_id = ''
  }
})

/** A new salary starts from the latest one's details (bank, structure...), from today. */
function openForm(salary: StaffSalary | null) {
  editing.value = salary
  const base = salary ?? history.value[0] ?? null
  form.basic_salary = base ? String(Number(base.basic_salary)) : ''
  form.currency = base?.currency ?? 'USD'
  form.salary_structure_id = base?.salary_structure_id ? String(base.salary_structure_id) : ''
  form.effective_from = salary?.effective_from ?? today()
  form.payment_method = base?.payment_method ?? 'bank'
  form.bank_name = base?.bank_name ?? ''
  form.bank_account_name = base?.bank_account_name ?? ''
  form.bank_account_number = base?.bank_account_number ?? ''
  form.note = salary?.note ?? ''
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  if (!detailStaff.value) return
  saving.value = true
  errors.value = {}
  saveError.value = null
  const bank = form.payment_method === 'bank'
  const input = {
    basic_salary: Number(form.basic_salary || 0),
    currency: form.currency,
    salary_structure_id: form.salary_structure_id ? Number(form.salary_structure_id) : null,
    effective_from: form.effective_from,
    payment_method: form.payment_method,
    bank_name: bank ? form.bank_name.trim() || null : null,
    bank_account_name: bank ? form.bank_account_name.trim() || null : null,
    bank_account_number: bank ? form.bank_account_number.trim() || null : null,
    note: form.note.trim() || null,
  }
  try {
    if (editing.value) await staffSalariesService.update(editing.value.id, input)
    else await staffSalariesService.create({ ...input, staff_id: detailStaff.value.id })
    formOpen.value = false
    await Promise.all([loadHistory(), fetch()])
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    saving.value = false
  }
}

async function removeSalary(salary: StaffSalary) {
  if (!(await confirmDialog.confirm({ message: t('admin.payroll.salaries.deleteConfirm', { date: formatDate(salary.effective_from) }), danger: true }))) return
  saveError.value = null
  try {
    await staffSalariesService.remove(salary.id)
    await Promise.all([loadHistory(), fetch()])
  } catch (e) {
    saveError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

/** Which history row is in effect today (the newest one that has started). */
const currentId = computed(() => history.value.find((s) => s.effective_from <= today())?.id ?? null)

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <label class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="missingOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('admin.payroll.salaries.missingOnly') }}
      </label>
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.payroll.salaries.hint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('admin.payroll.salaries.empty') }}
    </p>
    <template v-else>
      <!-- Cards on a phone (below sm) -->
      <div class="space-y-2 sm:hidden">
        <button
          v-for="row in items"
          :key="row.staff.id"
          type="button"
          class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]"
          @click="openDetail(row.staff)"
        >
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ row.staff.name }} <span class="font-normal text-neutral-500">({{ row.staff.employee_code }})</span></p>
            <span v-if="row.salary" class="shrink-0 text-sm font-semibold text-neutral-900">{{ money(row.salary) }}</span>
            <BaseBadge v-else variant="warning" class="shrink-0">{{ t('admin.payroll.salaries.notSet') }}</BaseBadge>
          </div>
          <p class="text-xs text-neutral-500">{{ [row.staff.position, row.staff.department].filter(Boolean).join(' · ') || '—' }}</p>
          <p v-if="row.salary?.structure" class="mt-1 text-xs text-neutral-600">{{ row.salary.structure.name }}</p>
          <p v-if="row.next_salary" class="mt-1 text-xs text-primary-700">
            {{ t('admin.payroll.salaries.nextFrom', { amount: money(row.next_salary), date: formatDate(row.next_salary.effective_from) }) }}
          </p>
        </button>
      </div>

      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.staff') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.salaries.position') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.tabs.basicSalary') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.salaries.structure') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.salaries.effectiveFrom') }}</th>
              <th class="px-4 py-3 font-medium">{{ t('admin.payroll.salaries.payment') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in items" :key="row.staff.id">
              <td class="px-4 py-3">
                <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="openDetail(row.staff)">{{ row.staff.name }}</button>
                <p class="text-xs text-neutral-500">{{ row.staff.employee_code }}</p>
              </td>
              <td class="px-4 py-3 text-neutral-700">{{ [row.staff.position, row.staff.department].filter(Boolean).join(' · ') || '—' }}</td>
              <td class="px-4 py-3 text-right">
                <span v-if="row.salary" class="font-semibold tabular-nums text-neutral-900">{{ money(row.salary) }}</span>
                <BaseBadge v-else variant="warning">{{ t('admin.payroll.salaries.notSet') }}</BaseBadge>
                <p v-if="row.next_salary" class="text-xs text-primary-700">
                  {{ t('admin.payroll.salaries.nextFrom', { amount: money(row.next_salary), date: formatDate(row.next_salary.effective_from) }) }}
                </p>
              </td>
              <td class="px-4 py-3 text-neutral-700">{{ row.salary?.structure?.name ?? '—' }}</td>
              <td class="px-4 py-3 text-neutral-700">{{ row.salary ? formatDate(row.salary.effective_from) : '—' }}</td>
              <td class="px-4 py-3 text-neutral-700">{{ row.salary ? t(`admin.payroll.salaries.${row.salary.payment_method}`) : '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal v-model="detailOpen" size="lg" :title="detailStaff ? `${detailStaff.name} (${detailStaff.employee_code ?? '—'})` : t('admin.payroll.tabs.basicSalary')">
      <BaseAlert v-if="saveError && !formOpen" variant="danger" class="mb-3">{{ saveError }}</BaseAlert>

      <form v-if="formOpen" class="space-y-4" @submit.prevent="save">
        <h3 class="text-sm font-semibold text-neutral-800">{{ editing ? t('admin.payroll.salaries.edit') : t('admin.payroll.salaries.set') }}</h3>
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-3">
          <BaseInput v-model="form.basic_salary" type="number" required :label="t('admin.payroll.salaries.monthlyBasic')" :error="errors.basic_salary?.[0]" />
          <BaseSelect
            :model-value="form.currency"
            :options="currencyOptions"
            required
            :label="t('admin.payroll.currency')"
            :error="errors.currency?.[0]"
            @update:model-value="form.currency = $event as PayCurrency"
          />
          <BaseInput v-model="form.effective_from" type="date" required :label="t('admin.payroll.salaries.effectiveFrom')" :error="errors.effective_from?.[0]" />
        </div>
        <BaseSelect
          v-model="form.salary_structure_id"
          :options="structureOptions"
          :label="t('admin.payroll.salaries.structure')"
          :hint="t('admin.payroll.salaries.structureHint')"
          :error="errors.salary_structure_id?.[0]"
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect
            :model-value="form.payment_method"
            :options="paymentOptions"
            :label="t('admin.payroll.salaries.payment')"
            @update:model-value="form.payment_method = $event as PaymentMethod"
          />
          <BaseInput v-if="form.payment_method === 'bank'" v-model="form.bank_name" :label="t('admin.payroll.salaries.bankName')" :error="errors.bank_name?.[0]" />
        </div>
        <div v-if="form.payment_method === 'bank'" class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.bank_account_name" :label="t('admin.payroll.salaries.accountName')" :error="errors.bank_account_name?.[0]" />
          <BaseInput v-model="form.bank_account_number" :label="t('admin.payroll.salaries.accountNumber')" :error="errors.bank_account_number?.[0]" />
        </div>
        <BaseInput v-model="form.note" :label="t('admin.payroll.note')" :error="errors.note?.[0]" />
        <div class="flex justify-end gap-2">
          <BaseButton variant="outline" @click="formOpen = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
        </div>
      </form>

      <template v-else>
        <div class="mb-3 flex items-center justify-between gap-3">
          <h3 class="text-sm font-semibold text-neutral-800">{{ t('admin.payroll.salaries.history') }}</h3>
          <BaseButton v-if="canManage" size="sm" @click="openForm(null)">{{ t('admin.payroll.salaries.set') }}</BaseButton>
        </div>
        <div v-if="detailLoading" class="flex justify-center py-8"><BaseSpinner /></div>
        <p v-else-if="history.length === 0" class="rounded-lg bg-neutral-50 px-3 py-6 text-center text-sm text-neutral-500">{{ t('admin.payroll.salaries.noHistory') }}</p>
        <ul v-else class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="salary in history" :key="salary.id" class="flex flex-wrap items-start justify-between gap-2 px-3 py-2.5">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-neutral-900">
                {{ money(salary) }}
                <BaseBadge v-if="salary.id === currentId" variant="success" class="ml-1">{{ t('admin.payroll.salaries.current') }}</BaseBadge>
                <BaseBadge v-else-if="salary.effective_from > today()" variant="primary" class="ml-1">{{ t('admin.payroll.salaries.upcoming') }}</BaseBadge>
              </p>
              <p class="text-xs text-neutral-500">
                {{ t('admin.payroll.salaries.fromDate', { date: formatDate(salary.effective_from) }) }}
                · {{ t(`admin.payroll.salaries.${salary.payment_method}`) }}<template v-if="salary.bank_name"> ({{ salary.bank_name }}<template v-if="salary.bank_account_number"> {{ salary.bank_account_number }}</template>)</template>
                <template v-if="salary.structure"> · {{ salary.structure.name }}</template>
              </p>
              <p v-if="salary.note" class="text-xs text-neutral-600">{{ salary.note }}</p>
            </div>
            <div v-if="canManage" class="flex shrink-0 gap-3">
              <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="openForm(salary)">{{ t('admin.payroll.edit') }}</button>
              <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeSalary(salary)">{{ t('admin.organization.delete') }}</button>
            </div>
          </li>
        </ul>
      </template>

      <template #footer>
        <BaseButton variant="outline" @click="detailOpen = false">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

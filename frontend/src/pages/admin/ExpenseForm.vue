<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import ExpenseEntityFormModal from '@/components/admin/ExpenseEntityFormModal.vue'
import SupplierFormModal from '@/components/admin/SupplierFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { accountsService, type Account } from '@/services/accounting'
import { expenseEntitiesService, type ExpenseEntity } from '@/services/expenseEntities'
import { expensesService, type ExpenseInput } from '@/services/expenses'
import { paymentMethods } from '@/services/payments'
import { suppliersService, type Supplier } from '@/services/suppliers'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { thisMonth } from '@/utils/date'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const accounts = ref<Account[]>([])
const loadingAccounts = ref(true)

// Who it is for (Accounting > Expense for): required.
const entities = ref<ExpenseEntity[]>([])
const entityOptions = computed(() => entities.value.map((e) => ({ value: String(e.id), label: e.name })))
const entityModalOpen = ref(false)
const canAddEntity = computed(() => auth.can('expense-for.create'))

function onEntitySaved(entity: ExpenseEntity) {
  if (entity.is_active) entities.value = [...entities.value, entity].sort((a, b) => a.name.localeCompare(b.name))
  if (entity.is_active) form.expense_entity_id = entity.id
}

// Vendors come from the shared Suppliers list (Accounting > Vendors).
const suppliers = ref<Supplier[]>([])
const supplierOptions = computed(() => suppliers.value.map((s) => ({ value: String(s.id), label: s.name })))
const supplierModalOpen = ref(false)
const canAddSupplier = computed(() => auth.can('suppliers.create'))

function onSupplierSaved(supplier: Supplier) {
  suppliers.value = [...suppliers.value, supplier].sort((a, b) => a.name.localeCompare(b.name))
  form.supplier_id = supplier.id
}

const expenseAccountOptions = computed(() =>
  accounts.value.filter((a) => a.type === 'EXPENSE' && a.is_active).map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })),
)

function toPascalCase(value: string): string {
  return value
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const paymentMethodOptions = computed(() => paymentMethods.map((method) => ({ value: method, label: t(`admin.payments.method${toPascalCase(method)}`) })))

const currencyOptions = computed(() => [
  { value: 'USD', label: t('admin.coursePackages.currencyUsd') },
  { value: 'KHR', label: t('admin.coursePackages.currencyKhr') },
])

const form = reactive<ExpenseInput>({
  expense_date: today(),
  for_month: thisMonth(),
  account_id: null,
  expense_entity_id: null,
  amount: '',
  // Starts on the school's own currency.
  currency: auth.tenantDefaultCurrency ?? 'USD',
  payment_method: '',
  supplier_id: null,
  vendor: '',
  description: '',
  reference_number: '',
})

// For month follows the expense date until it is picked by hand
// (paid today for September: pick September).
const monthPicked = ref(false)
watch(
  () => form.expense_date,
  (date) => {
    if (!monthPicked.value && date) form.for_month = date.slice(0, 7)
  },
)
function onMonth(value: string) {
  form.for_month = value
  monthPicked.value = true
}

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

onMounted(async () => {
  loadingAccounts.value = true
  try {
    ;[accounts.value, suppliers.value, entities.value] = await Promise.all([
      accountsService.listAll(),
      suppliersService.listAll().catch(() => []),
      expenseEntitiesService.listAll().catch(() => []),
    ])
    if (entities.value.length === 1) form.expense_entity_id = entities.value[0]!.id
  } finally {
    loadingAccounts.value = false
  }
})

async function submit() {
  if (!form.account_id || !form.expense_entity_id) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const expense = await expensesService.create(form)
    await router.push(`/admin/expenses/${expense.id}`)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.expenses.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.expenses.createTitle') }}</h1>
    </div>

    <form class="space-y-6" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <div>
        <SearchableSelect
          :model-value="form.expense_entity_id !== null ? String(form.expense_entity_id) : ''"
          :options="entityOptions"
          :disabled="loadingAccounts"
          :placeholder="t('admin.expenses.selectExpenseFor')"
          :label="t('admin.expenses.expenseFor')"
          required
          :error="errors.expense_entity_id?.[0]"
          @update:model-value="form.expense_entity_id = $event ? Number($event) : null"
        />
        <button v-if="canAddEntity" type="button" class="mt-1 text-xs font-medium text-primary-600 hover:text-primary-700" @click="entityModalOpen = true">
          {{ t('admin.expenses.addExpenseFor') }}
        </button>
      </div>

      <SearchableSelect
        :model-value="form.account_id !== null ? String(form.account_id) : ''"
        :options="expenseAccountOptions"
        :disabled="loadingAccounts"
        :placeholder="t('admin.expenses.selectAccount')"
        :label="t('admin.expenses.account')"
        required
        :error="errors.account_id?.[0]"
        @update:model-value="form.account_id = $event ? Number($event) : null"
      />

      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.expense_date" type="date" required :label="t('admin.expenses.expenseDate')" :error="errors.expense_date?.[0]" />
        <BaseInput
          :model-value="form.for_month"
          type="month"
          required
          :label="t('admin.expenses.forMonth')"
          :hint="t('admin.expenses.forMonthHint')"
          :error="errors.for_month?.[0]"
          @update:model-value="onMonth"
        />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <BaseInput
          :model-value="form.amount"
          type="number"
          required
          :label="t('admin.expenses.amount')"
          :error="errors.amount?.[0]"
          @update:model-value="form.amount = $event"
        />
        <BaseSelect v-model="form.currency" :options="currencyOptions" required :label="t('admin.expenses.currency')" :error="errors.currency?.[0]" />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <SearchableSelect
            :model-value="form.supplier_id !== null ? String(form.supplier_id) : ''"
            :options="supplierOptions"
            :disabled="loadingAccounts"
            :placeholder="t('admin.expenses.selectVendor')"
            :label="t('admin.expenses.vendor')"
            :error="errors.supplier_id?.[0]"
            @update:model-value="form.supplier_id = $event ? Number($event) : null"
          />
          <button v-if="canAddSupplier" type="button" class="mt-1 text-xs font-medium text-primary-600 hover:text-primary-700" @click="supplierModalOpen = true">
            {{ t('admin.expenses.addVendor') }}
          </button>
        </div>
        <SearchableSelect
          v-model="form.payment_method"
          :options="paymentMethodOptions"
          :placeholder="t('admin.expenses.selectPaymentMethod')"
          :label="t('admin.expenses.paymentMethod')"
        />
      </div>

      <BaseInput v-model="form.reference_number" :label="t('admin.expenses.referenceNumber')" :error="errors.reference_number?.[0]" />

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.expenses.description') }}</label>
        <textarea
          v-model="form.description"
          rows="3"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>

      <div class="flex gap-3">
        <BaseButton type="submit" :loading="submitting" :disabled="!form.account_id || !form.expense_entity_id">{{ t('common.save') }}</BaseButton>
        <BaseButton type="button" variant="outline" @click="router.push('/admin/expenses')">{{ t('common.cancel') }}</BaseButton>
      </div>
    </form>

    <SupplierFormModal v-model="supplierModalOpen" @saved="onSupplierSaved" />
    <ExpenseEntityFormModal v-model="entityModalOpen" @saved="onEntitySaved" />
  </div>
</template>

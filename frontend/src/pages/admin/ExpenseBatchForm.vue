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
import { expensesService, MAX_EXPENSE_LINES, type ExpenseBatchLine } from '@/services/expenses'
import { paymentMethods } from '@/services/payments'
import { suppliersService, type Supplier } from '@/services/suppliers'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { thisMonth } from '@/utils/date'

/**
 * Expenses > Add many: several expenses on one date in one save. Date,
 * month, Expense for, currency, payment method and vendor are filled once;
 * each line has its own account, amount and description. One bad line
 * saves none.
 */
const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const accounts = ref<Account[]>([])
const entities = ref<ExpenseEntity[]>([])
const suppliers = ref<Supplier[]>([])
const loading = ref(true)

const entityOptions = computed(() => entities.value.map((e) => ({ value: String(e.id), label: e.name })))
const supplierOptions = computed(() => suppliers.value.map((s) => ({ value: String(s.id), label: s.name })))
const accountOptions = computed(() =>
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

const entityModalOpen = ref(false)
const supplierModalOpen = ref(false)
const canAddEntity = computed(() => auth.can('expense-for.create'))
const canAddSupplier = computed(() => auth.can('suppliers.create'))

function onEntitySaved(entity: ExpenseEntity) {
  if (!entity.is_active) return
  entities.value = [...entities.value, entity].sort((a, b) => a.name.localeCompare(b.name))
  shared.expense_entity_id = entity.id
}
function onSupplierSaved(supplier: Supplier) {
  suppliers.value = [...suppliers.value, supplier].sort((a, b) => a.name.localeCompare(b.name))
  shared.supplier_id = supplier.id
}

const shared = reactive({
  expense_date: today(),
  for_month: thisMonth(),
  expense_entity_id: null as number | null,
  currency: (auth.tenantDefaultCurrency ?? 'USD') as 'USD' | 'KHR',
  payment_method: '',
  supplier_id: null as number | null,
})

// For month follows the date until it is picked by hand.
const monthPicked = ref(false)
watch(
  () => shared.expense_date,
  (date) => {
    if (!monthPicked.value && date) shared.for_month = date.slice(0, 7)
  },
)
function onMonth(value: string) {
  shared.for_month = value
  monthPicked.value = true
}

let nextKey = 1
const blankLine = (): ExpenseBatchLine & { key: number } => ({ key: nextKey++, account_id: null, amount: '', description: '', reference_number: '' })
const lines = ref([blankLine(), blankLine(), blankLine()])

function addLine() {
  if (lines.value.length < MAX_EXPENSE_LINES) lines.value.push(blankLine())
}
function removeLine(index: number) {
  lines.value.splice(index, 1)
  if (!lines.value.length) lines.value.push(blankLine())
}

/** A line with nothing in it is left out, so spare empty rows don't block Save. */
const isBlank = (line: ExpenseBatchLine) => !line.account_id && !line.amount && !line.description.trim() && !line.reference_number.trim()
const filled = computed(() => lines.value.filter((l) => !isBlank(l)))
const total = computed(() => filled.value.reduce((sum, l) => sum + (Number(l.amount) || 0), 0))
const totalText = computed(() =>
  shared.currency === 'KHR' ? `${Math.round(total.value).toLocaleString('en-US')} ៛` : `$${total.value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
)

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

/** Server errors name a line by its place among the lines sent ("lines.1.amount"); shown on that row. */
const sentKeys = ref<number[]>([])
function lineError(key: number, field: string): string | undefined {
  const index = sentKeys.value.indexOf(key)
  return index === -1 ? undefined : errors.value[`lines.${index}.${field}`]?.[0]
}
const badLines = computed(() => new Set(Object.keys(errors.value).filter((k) => k.startsWith('lines.')).map((k) => sentKeys.value[Number(k.split('.')[1])])))

onMounted(async () => {
  try {
    ;[accounts.value, suppliers.value, entities.value] = await Promise.all([
      accountsService.listAll(),
      suppliersService.listAll().catch(() => []),
      expenseEntitiesService.listAll().catch(() => []),
    ])
    if (entities.value.length === 1) shared.expense_entity_id = entities.value[0]!.id
  } finally {
    loading.value = false
  }
})

async function submit() {
  errors.value = {}
  generalError.value = null
  if (!filled.value.length) {
    generalError.value = t('admin.expenses.batch.noLines')
    return
  }
  submitting.value = true
  sentKeys.value = filled.value.map((l) => l.key)
  try {
    const saved = await expensesService.createMany({ ...shared, lines: filled.value })
    await router.push({ path: '/admin/expenses', query: { added: String(saved.length) } })
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
      generalError.value = t('admin.expenses.batch.fixLines')
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.expenses.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="max-w-5xl">
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.expenses.batch.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.expenses.batch.subtitle') }}</p>
    </div>

    <form class="space-y-6" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <!-- Same for every line -->
      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <h2 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('admin.expenses.batch.sameForAll') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <BaseInput v-model="shared.expense_date" type="date" required :label="t('admin.expenses.expenseDate')" :error="errors.expense_date?.[0]" />
          <BaseInput
            :model-value="shared.for_month"
            type="month"
            required
            :label="t('admin.expenses.forMonth')"
            :error="errors.for_month?.[0]"
            @update:model-value="onMonth"
          />
          <div>
            <SearchableSelect
              :model-value="shared.expense_entity_id !== null ? String(shared.expense_entity_id) : ''"
              :options="entityOptions"
              :disabled="loading"
              :placeholder="t('admin.expenses.selectExpenseFor')"
              :label="t('admin.expenses.expenseFor')"
              required
              :error="errors.expense_entity_id?.[0]"
              @update:model-value="shared.expense_entity_id = $event ? Number($event) : null"
            />
            <button v-if="canAddEntity" type="button" class="mt-1 text-xs font-medium text-primary-600 hover:text-primary-700" @click="entityModalOpen = true">
              {{ t('admin.expenses.addExpenseFor') }}
            </button>
          </div>
          <BaseSelect v-model="shared.currency" :options="currencyOptions" required :label="t('admin.expenses.currency')" :error="errors.currency?.[0]" />
          <SearchableSelect
            v-model="shared.payment_method"
            :options="paymentMethodOptions"
            :placeholder="t('admin.expenses.selectPaymentMethod')"
            :label="t('admin.expenses.paymentMethod')"
            :error="errors.payment_method?.[0]"
          />
          <div>
            <SearchableSelect
              :model-value="shared.supplier_id !== null ? String(shared.supplier_id) : ''"
              :options="supplierOptions"
              :disabled="loading"
              :placeholder="t('admin.expenses.selectVendor')"
              :label="t('admin.expenses.vendor')"
              :error="errors.supplier_id?.[0]"
              @update:model-value="shared.supplier_id = $event ? Number($event) : null"
            />
            <button v-if="canAddSupplier" type="button" class="mt-1 text-xs font-medium text-primary-600 hover:text-primary-700" @click="supplierModalOpen = true">
              {{ t('admin.expenses.addVendor') }}
            </button>
          </div>
        </div>
      </section>

      <!-- One expense per line -->
      <section>
        <div class="mb-2 flex items-center justify-between gap-2">
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.expenses.batch.lines', { n: filled.length }) }}</h2>
          <p class="text-sm text-neutral-700">
            {{ t('admin.expenses.batch.total') }} <span class="font-semibold tabular-nums">{{ totalText }}</span>
          </p>
        </div>

        <!-- Desktop header -->
        <div class="hidden gap-2 px-3 pb-1 text-xs font-medium text-neutral-500 md:grid md:grid-cols-[2rem_minmax(0,2fr)_8rem_minmax(0,2fr)_9rem_2.5rem]">
          <span>#</span>
          <span>{{ t('admin.expenses.account') }} *</span>
          <span>{{ t('admin.expenses.amount') }} *</span>
          <span>{{ t('admin.expenses.description') }}</span>
          <span>{{ t('admin.expenses.referenceNumber') }}</span>
          <span />
        </div>

        <div class="space-y-2">
          <div
            v-for="(line, index) in lines"
            :key="line.key"
            class="rounded-[--radius-card] border bg-white p-3 shadow-[--shadow-card] md:grid md:grid-cols-[2rem_minmax(0,2fr)_8rem_minmax(0,2fr)_9rem_2.5rem] md:items-start md:gap-2 md:p-2"
            :class="badLines.has(line.key) ? 'border-danger-400' : 'border-neutral-200'"
          >
            <div class="mb-2 flex items-center justify-between md:mb-0 md:block md:pt-2.5">
              <span class="text-sm font-medium text-neutral-500">{{ index + 1 }}</span>
              <button type="button" class="text-sm text-danger-600 md:hidden" @click="removeLine(index)">{{ t('admin.expenses.batch.remove') }}</button>
            </div>
            <SearchableSelect
              :model-value="line.account_id !== null ? String(line.account_id) : ''"
              :options="accountOptions"
              :disabled="loading"
              :placeholder="t('admin.expenses.selectAccount')"
              :aria-label="t('admin.expenses.account')"
              :error="lineError(line.key, 'account_id')"
              class="mb-2 md:mb-0"
              @update:model-value="line.account_id = $event ? Number($event) : null"
            />
            <BaseInput
              v-model="line.amount"
              type="number"
              :placeholder="t('admin.expenses.amount')"
              :aria-label="t('admin.expenses.amount')"
              :error="lineError(line.key, 'amount')"
              class="mb-2 md:mb-0"
            />
            <BaseInput
              v-model="line.description"
              :placeholder="t('admin.expenses.description')"
              :aria-label="t('admin.expenses.description')"
              :error="lineError(line.key, 'description')"
              class="mb-2 md:mb-0"
            />
            <BaseInput
              v-model="line.reference_number"
              :placeholder="t('admin.expenses.referenceNumber')"
              :aria-label="t('admin.expenses.referenceNumber')"
              :error="lineError(line.key, 'reference_number')"
            />
            <button
              type="button"
              class="hidden h-10 w-10 items-center justify-center rounded-lg text-neutral-400 hover:bg-danger-50 hover:text-danger-600 md:flex"
              :title="t('admin.expenses.batch.remove')"
              @click="removeLine(index)"
            >
              ✕
            </button>
          </div>
        </div>

        <BaseButton type="button" variant="outline" size="sm" class="mt-3" :disabled="lines.length >= MAX_EXPENSE_LINES" @click="addLine">
          {{ t('admin.expenses.batch.addLine') }}
        </BaseButton>
        <p class="mt-2 text-xs text-neutral-500">{{ t('admin.expenses.batch.hint', { max: MAX_EXPENSE_LINES }) }}</p>
      </section>

      <div class="flex gap-3">
        <BaseButton type="submit" :loading="submitting" :disabled="loading || !shared.expense_entity_id || !filled.length">
          {{ t('admin.expenses.batch.save', { n: filled.length }) }}
        </BaseButton>
        <BaseButton type="button" variant="outline" @click="router.push('/admin/expenses')">{{ t('common.cancel') }}</BaseButton>
      </div>
    </form>

    <SupplierFormModal v-model="supplierModalOpen" @saved="onSupplierSaved" />
    <ExpenseEntityFormModal v-model="entityModalOpen" @saved="onEntitySaved" />
  </div>
</template>

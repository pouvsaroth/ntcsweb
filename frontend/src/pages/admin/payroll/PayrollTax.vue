<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import StaffProfileList from '@/pages/admin/payroll/StaffProfileList.vue'
import {
  payAmountLabel,
  payrollRulesService,
  riel,
  type PayCurrency,
  type PayrollPreview,
  type PayrollRules,
} from '@/services/payroll'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Payroll > Tax — Cambodia's monthly Tax on Salary as the school sets
 * it: progressive brackets in riel, the spouse/child allowances a resident
 * gets off first, and a non-resident's flat rate; a calculator; and each
 * staff member's residence and dependants. A USD salary is taxed at the
 * school's currency rate (Settings > Currency rates).
 */
const { t } = useI18n()
const auth = useAuthStore()
const canManage = computed(() => auth.can('payroll.manage'))

const rules = ref<PayrollRules | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    rules.value = await payrollRulesService.get()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
  } finally {
    loading.value = false
  }
}

function bracketLabel(min: number | string, max: number | string | null): string {
  return max === null ? t('admin.payroll.tax.over', { amount: riel(min) }) : `${riel(min)} – ${riel(max)}`
}

// --- Brackets ---------------------------------------------------------------------------

const bracketsOpen = ref(false)
const brackets = ref<{ min_amount: string; max_amount: string; rate: string }[]>([])
const bracketErrors = ref<Record<string, string[]>>({})
const bracketError = ref<string | null>(null)
const savingBrackets = ref(false)

function openBrackets() {
  brackets.value = (rules.value?.tax_brackets ?? []).map((b) => ({
    min_amount: String(Number(b.min_amount)),
    max_amount: b.max_amount === null ? '' : String(Number(b.max_amount)),
    rate: String(Number(b.rate)),
  }))
  bracketErrors.value = {}
  bracketError.value = null
  bracketsOpen.value = true
}

/** A new band starts where the last one ends. */
function addBracket() {
  const last = brackets.value[brackets.value.length - 1]
  brackets.value.push({ min_amount: last?.max_amount || last?.min_amount || '0', max_amount: '', rate: '' })
}

async function saveBrackets() {
  savingBrackets.value = true
  bracketErrors.value = {}
  bracketError.value = null
  try {
    rules.value = await payrollRulesService.replaceBrackets(
      brackets.value.map((b) => ({ min_amount: Number(b.min_amount || 0), max_amount: b.max_amount === '' ? null : Number(b.max_amount), rate: Number(b.rate || 0) })),
    )
    bracketsOpen.value = false
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) bracketErrors.value = e.errors
    else bracketError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    savingBrackets.value = false
  }
}

// --- Allowances & non-resident rate ------------------------------------------------------------

const allowancesOpen = ref(false)
const allowances = reactive({ tax_spouse_allowance: '', tax_child_allowance: '', tax_non_resident_rate: '' })
const allowanceErrors = ref<Record<string, string[]>>({})
const savingAllowances = ref(false)

function openAllowances() {
  const s = rules.value?.settings
  if (!s) return
  allowances.tax_spouse_allowance = String(s.tax_spouse_allowance)
  allowances.tax_child_allowance = String(s.tax_child_allowance)
  allowances.tax_non_resident_rate = String(s.tax_non_resident_rate)
  allowanceErrors.value = {}
  allowancesOpen.value = true
}

async function saveAllowances() {
  savingAllowances.value = true
  allowanceErrors.value = {}
  try {
    rules.value = await payrollRulesService.updateSettings({
      tax_spouse_allowance: Number(allowances.tax_spouse_allowance || 0),
      tax_child_allowance: Number(allowances.tax_child_allowance || 0),
      tax_non_resident_rate: Number(allowances.tax_non_resident_rate || 0),
    })
    allowancesOpen.value = false
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) allowanceErrors.value = e.errors
  } finally {
    savingAllowances.value = false
  }
}

// --- Calculator --------------------------------------------------------------------------------

const calc = reactive({ amount: '', currency: 'USD' as PayCurrency, tax_resident: true, spouse_dependent: false, child_dependents: '0' })
const result = ref<PayrollPreview | null>(null)
const calculating = ref(false)
const calcError = ref<string | null>(null)
const currencyOptions = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]

async function calculate() {
  if (!calc.amount) return
  calculating.value = true
  calcError.value = null
  try {
    result.value = await payrollRulesService.preview({
      amount: Number(calc.amount),
      currency: calc.currency,
      tax_resident: calc.tax_resident,
      spouse_dependent: calc.spouse_dependent,
      child_dependents: Number(calc.child_dependents || 0),
      social_security_enrolled: true,
    })
  } catch (e) {
    calcError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
  } finally {
    calculating.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div class="space-y-6">
    <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
    <div v-if="loading && !rules" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-if="rules">
      <BaseAlert v-if="rules.khr_per_usd === null" variant="warning">{{ t('admin.payroll.tax.noRate') }}</BaseAlert>

      <div class="grid gap-4 lg:grid-cols-2">
        <!-- Brackets -->
        <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <div class="mb-3 flex items-start justify-between gap-3">
            <div>
              <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.payroll.tax.brackets') }}</h2>
              <p class="text-sm text-neutral-500">{{ t('admin.payroll.tax.bracketsHint') }}</p>
            </div>
            <BaseButton v-if="canManage" size="sm" variant="outline" @click="openBrackets">{{ t('admin.payroll.edit') }}</BaseButton>
          </div>
          <table class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(bracket, index) in rules.tax_brackets" :key="index">
                <td class="py-1.5 text-neutral-700">{{ bracketLabel(bracket.min_amount, bracket.max_amount) }}</td>
                <td class="py-1.5 text-right font-semibold tabular-nums text-neutral-900">{{ Number(bracket.rate) }}%</td>
              </tr>
            </tbody>
          </table>
        </section>

        <!-- Allowances -->
        <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <div class="mb-3 flex items-start justify-between gap-3">
            <div>
              <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.payroll.tax.allowances') }}</h2>
              <p class="text-sm text-neutral-500">{{ t('admin.payroll.tax.allowancesHint') }}</p>
            </div>
            <BaseButton v-if="canManage" size="sm" variant="outline" @click="openAllowances">{{ t('admin.payroll.edit') }}</BaseButton>
          </div>
          <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
            <div><dt class="text-neutral-500">{{ t('admin.payroll.tax.spouse') }}</dt><dd class="font-semibold text-neutral-900">{{ riel(rules.settings.tax_spouse_allowance) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.payroll.tax.child') }}</dt><dd class="font-semibold text-neutral-900">{{ riel(rules.settings.tax_child_allowance) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('admin.payroll.tax.nonResident') }}</dt><dd class="font-semibold text-neutral-900">{{ rules.settings.tax_non_resident_rate }}%</dd></div>
          </dl>
          <p v-if="rules.khr_per_usd" class="mt-3 text-xs text-neutral-500">{{ t('admin.payroll.tax.rateToday', { rate: rules.khr_per_usd.toLocaleString() }) }}</p>
        </section>
      </div>

      <!-- Calculator -->
      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.payroll.tax.calculator') }}</h2>
        <p class="mb-3 text-sm text-neutral-500">{{ t('admin.payroll.tax.calculatorHint') }}</p>
        <form class="grid items-end gap-3 sm:grid-cols-[10rem_8rem_auto_auto_6rem_auto]" @submit.prevent="calculate">
          <BaseInput v-model="calc.amount" type="number" :label="t('admin.payroll.tax.monthlyPay')" />
          <BaseSelect :model-value="calc.currency" :options="currencyOptions" :label="t('admin.payroll.currency')" @update:model-value="calc.currency = $event as PayCurrency" />
          <label class="flex items-center gap-2 pb-2 text-sm text-neutral-700">
            <input v-model="calc.tax_resident" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.payroll.profiles.resident') }}
          </label>
          <label class="flex items-center gap-2 pb-2 text-sm text-neutral-700">
            <input v-model="calc.spouse_dependent" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.payroll.profiles.spouse') }}
          </label>
          <BaseInput v-model="calc.child_dependents" type="number" :label="t('admin.payroll.tax.children')" />
          <BaseButton :loading="calculating" @click="calculate">{{ t('admin.payroll.tax.calculate') }}</BaseButton>
        </form>
        <BaseAlert v-if="calcError" variant="danger" class="mt-3">{{ calcError }}</BaseAlert>
        <div v-if="result" class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
          <dl class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-1 rounded-lg bg-neutral-50 p-3">
            <dt class="text-neutral-500">{{ t('admin.payroll.tax.wageInRiel') }}</dt><dd class="text-right tabular-nums">{{ riel(result.wage_khr) }}</dd>
            <dt class="text-neutral-500">{{ t('admin.payroll.tax.nssfStaff') }}</dt><dd class="text-right tabular-nums">− {{ riel(result.social_security.reduces_taxable) }}</dd>
            <dt class="text-neutral-500">{{ t('admin.payroll.tax.allowances') }}</dt><dd class="text-right tabular-nums">− {{ riel(result.tax.allowances) }}</dd>
            <dt class="text-neutral-500">{{ t('admin.payroll.tax.taxBase') }}</dt><dd class="text-right tabular-nums">{{ riel(result.tax.base) }}</dd>
            <dt class="font-semibold text-neutral-800">{{ t('admin.payroll.tabs.tax') }}</dt><dd class="text-right font-semibold tabular-nums">{{ riel(result.tax.tax) }}</dd>
          </dl>
          <dl class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-1 rounded-lg bg-primary-50 p-3">
            <dt class="text-neutral-600">{{ t('admin.payroll.tabs.tax') }}</dt><dd class="text-right tabular-nums">{{ payAmountLabel(result.in_currency.tax, 'fixed', result.in_currency.currency) }}</dd>
            <dt class="text-neutral-600">{{ t('admin.payroll.tax.nssfStaff') }}</dt><dd class="text-right tabular-nums">{{ payAmountLabel(result.in_currency.social_security_employee, 'fixed', result.in_currency.currency) }}</dd>
            <dt class="text-neutral-600">{{ t('admin.payroll.tax.nssfSchool') }}</dt><dd class="text-right tabular-nums">{{ payAmountLabel(result.in_currency.social_security_employer, 'fixed', result.in_currency.currency) }}</dd>
            <dt class="font-semibold text-neutral-800">{{ t('admin.payroll.tax.takeHome') }}</dt><dd class="text-right font-semibold tabular-nums">{{ payAmountLabel(result.in_currency.net, 'fixed', result.in_currency.currency) }}</dd>
          </dl>
          <p v-if="result.needs_rate" class="text-sm text-amber-700 sm:col-span-2">{{ t('admin.payroll.tax.noRate') }}</p>
        </div>
      </section>
    </template>

    <StaffProfileList mode="tax" />

    <BaseModal v-model="bracketsOpen" size="lg" :title="t('admin.payroll.tax.brackets')">
      <BaseAlert v-if="bracketError" variant="danger" class="mb-3">{{ bracketError }}</BaseAlert>
      <p class="mb-3 text-sm text-neutral-600">{{ t('admin.payroll.tax.bracketsEditHint') }}</p>
      <div class="mb-1 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_6rem_auto] gap-2 text-xs font-medium text-neutral-500">
        <span>{{ t('admin.payroll.tax.from') }}</span><span>{{ t('admin.payroll.tax.to') }}</span><span>{{ t('admin.payroll.tax.rate') }}</span><span />
      </div>
      <div v-for="(bracket, index) in brackets" :key="index" class="mb-2 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_6rem_auto] items-start gap-2">
        <BaseInput v-model="bracket.min_amount" type="number" :error="bracketErrors[`brackets.${index}.min_amount`]?.[0]" />
        <BaseInput v-model="bracket.max_amount" type="number" :placeholder="t('admin.payroll.tax.noTop')" :error="bracketErrors[`brackets.${index}.max_amount`]?.[0]" />
        <BaseInput v-model="bracket.rate" type="number" placeholder="%" :error="bracketErrors[`brackets.${index}.rate`]?.[0]" />
        <button type="button" class="mt-2 text-sm font-medium text-danger-600" :aria-label="t('admin.organization.delete')" @click="brackets.splice(index, 1)">✕</button>
      </div>
      <button type="button" class="text-sm font-medium text-primary-700 hover:underline" @click="addBracket">+ {{ t('admin.payroll.tax.addBracket') }}</button>
      <template #footer>
        <BaseButton variant="outline" @click="bracketsOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="savingBrackets" @click="saveBrackets">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <BaseModal v-model="allowancesOpen" :title="t('admin.payroll.tax.allowances')">
      <form class="space-y-4" @submit.prevent="saveAllowances">
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="allowances.tax_spouse_allowance" type="number" :label="t('admin.payroll.tax.spouseRiel')" :error="allowanceErrors.tax_spouse_allowance?.[0]" />
          <BaseInput v-model="allowances.tax_child_allowance" type="number" :label="t('admin.payroll.tax.childRiel')" :error="allowanceErrors.tax_child_allowance?.[0]" />
        </div>
        <BaseInput v-model="allowances.tax_non_resident_rate" type="number" :label="t('admin.payroll.tax.nonResidentRate')" :error="allowanceErrors.tax_non_resident_rate?.[0]" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="allowancesOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="savingAllowances" @click="saveAllowances">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

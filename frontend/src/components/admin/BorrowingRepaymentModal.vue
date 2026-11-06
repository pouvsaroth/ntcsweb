<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { accountsService, type Account } from '@/services/accounting'
import { borrowingsService, type Borrowing } from '@/services/borrowings'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'

/**
 * Accounting > Borrowings: record one payment back to a lender. Filled in
 * from the loan's next unpaid month; principal lowers the debt, interest is
 * recorded as a paid expense (Loan Interest) for the loan's Borrowed for.
 */
const props = defineProps<{ modelValue: boolean; loan: Borrowing | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [loan: Borrowing] }>()

const { t } = useI18n()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const accounts = ref<Account[]>([])
const accountOptions = computed(() => accounts.value.map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })))

const form = reactive({ paid_on: '', principal: '', interest: '', cash_account_id: '', note: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const money = (value: number) => formatMoney(value, props.loan?.currency ?? 'USD')
const total = computed(() => Number(form.principal || 0) + Number(form.interest || 0))

watch(
  () => [props.modelValue, props.loan] as const,
  async ([open, loan]) => {
    if (!open || !loan) return
    const next = loan.next_payment
    form.paid_on = today()
    // A flexible loan has no plan: suggest one month's interest, no principal.
    form.principal = next ? String(next.due_principal ?? next.principal) : ''
    form.interest = next ? String(next.interest) : loan.month_interest > 0 ? String(loan.month_interest) : ''
    form.cash_account_id = loan.cash_account ? String(loan.cash_account.id) : ''
    form.note = ''
    errors.value = {}
    saveError.value = null
    if (accounts.value.length === 0) {
      accounts.value = await accountsService
        .listAll()
        .then((all) => all.filter((a) => a.is_bank_or_cash && a.is_active))
        .catch(() => [])
      if (!form.cash_account_id && accounts.value.length === 1) form.cash_account_id = String(accounts.value[0]!.id)
    }
  },
  { immediate: true },
)

// Quick choices; any amount and any date can still be typed.
function interestOnly() {
  if (!props.loan) return
  form.principal = '0'
  form.interest = String(props.loan.next_payment?.interest ?? props.loan.month_interest ?? 0)
}

function payOff() {
  if (!props.loan) return
  form.principal = String(props.loan.outstanding)
}

async function save() {
  if (!props.loan) return
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const loan = await borrowingsService.repay(props.loan.id, {
      paid_on: form.paid_on,
      principal: Number(form.principal || 0),
      interest: Number(form.interest || 0),
      cash_account_id: form.cash_account_id ? Number(form.cash_account_id) : null,
      note: form.note.trim() || null,
    })
    emit('saved', loan)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.borrowings.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.borrowings.paymentFor', { name: loan?.lender?.name ?? '—' })" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="loan" class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <p class="text-sm text-neutral-600">
        {{ t('admin.borrowings.outstanding') }}: <span class="font-semibold text-neutral-900">{{ money(loan.outstanding) }}</span>
        <template v-if="loan.borrowed_for"> · {{ t('admin.borrowings.forName', { name: loan.borrowed_for.name }) }}</template>
      </p>
      <p v-if="loan.next_payment" class="text-xs text-neutral-500">{{ t('admin.borrowings.suggestedHint', { no: loan.next_payment.no }) }}</p>
      <p class="text-xs text-neutral-500">{{ t('admin.borrowings.payAnyHint') }}</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="rounded-lg bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 hover:bg-primary-100" @click="interestOnly">{{ t('admin.borrowings.interestOnly') }}</button>
        <button type="button" class="rounded-lg bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 hover:bg-primary-100" @click="payOff">{{ t('admin.borrowings.payOffAll') }}</button>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.principal" type="number" :label="t('admin.borrowings.principalPaid')" :error="errors.principal?.[0]" />
        <BaseInput v-model="form.interest" type="number" :label="t('admin.borrowings.interestPaid')" :error="errors.interest?.[0]" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.paid_on" type="date" required :label="t('admin.borrowings.paidOn')" :error="errors.paid_on?.[0]" />
        <SearchableSelect v-model="form.cash_account_id" required :options="accountOptions" :label="t('admin.borrowings.paidFrom')" :placeholder="t('admin.borrowings.selectAccount')" :error="errors.cash_account_id?.[0]" />
      </div>
      <BaseInput v-model="form.note" :label="t('admin.borrowings.note')" :error="errors.note?.[0]" />
      <p class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-700">
        {{ t('admin.borrowings.payTotal') }}: <span class="font-semibold text-neutral-900">{{ money(total) }}</span>
      </p>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="total <= 0 || !form.cash_account_id" @click="save">{{ t('admin.borrowings.recordPayment') }}</BaseButton>
    </template>
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseInput from '@/components/ui/BaseInput.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import type { Account } from '@/services/accounting'
import type { PaidNow } from '@/services/expenses'

/**
 * Add expense / Add many: "Paid now" (ticked by default) saves the expense
 * approved and paid from the picked cash/bank account. Only shown to someone
 * who may both approve and pay. The account picked last time is remembered.
 */
const props = defineProps<{ accounts: Account[]; expenseDate: string; disabled?: boolean; errors?: Record<string, string[]> }>()
const model = defineModel<PaidNow>({ required: true })

const { t } = useI18n()
const STORE_KEY = 'expenses.paidNowCashAccount'

const cashOptions = computed(() =>
  props.accounts.filter((a) => a.is_bank_or_cash && a.is_active).map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })),
)

// Pick the remembered account (or the only one) once the accounts arrive.
watch(
  cashOptions,
  (options) => {
    if (model.value.cash_account_id !== null || !options.length) return
    let remembered: string | null = null
    try {
      remembered = localStorage.getItem(STORE_KEY)
    } catch {
      remembered = null
    }
    const pick = options.find((o) => o.value === remembered) ?? (options.length === 1 ? options[0] : undefined)
    if (pick) model.value.cash_account_id = Number(pick.value)
  },
  { immediate: true },
)

function onAccount(value: string) {
  model.value.cash_account_id = value ? Number(value) : null
  try {
    if (value) localStorage.setItem(STORE_KEY, value)
  } catch {
    // Remembering is only a convenience.
  }
}

// Paid date follows the expense date until changed by hand.
let datePicked = false
watch(
  () => props.expenseDate,
  (date) => {
    if (!datePicked) model.value.paid_date = date
  },
  { immediate: true },
)
function onDate(value: string | number) {
  model.value.paid_date = String(value)
  datePicked = true
}
</script>

<template>
  <section class="rounded-lg border p-3" :class="model.paid_now ? 'border-primary-200 bg-primary-50/40' : 'border-neutral-200'">
    <label class="flex items-start gap-2">
      <input v-model="model.paid_now" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
      <span>
        <span class="block text-sm font-medium text-neutral-800">{{ t('admin.expenses.paidNow.label') }}</span>
        <span class="block text-xs text-neutral-500">{{ t('admin.expenses.paidNow.hint') }}</span>
      </span>
    </label>
    <div v-if="model.paid_now" class="mt-3 grid gap-3 sm:grid-cols-2">
      <SearchableSelect
        :model-value="model.cash_account_id !== null ? String(model.cash_account_id) : ''"
        :options="cashOptions"
        :disabled="disabled"
        required
        :label="t('admin.expenses.cashAccount')"
        :error="errors?.cash_account_id?.[0]"
        @update:model-value="onAccount"
      />
      <BaseInput :model-value="model.paid_date" type="date" :label="t('admin.expenses.paidDate')" :error="errors?.paid_date?.[0]" @update:model-value="onDate" />
    </div>
  </section>
</template>

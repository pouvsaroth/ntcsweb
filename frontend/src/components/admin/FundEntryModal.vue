<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import FundProviderPicker from '@/components/admin/FundProviderPicker.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { accountsService, type Account } from '@/services/accounting'
import { fundsService, providedByIds, providedByValue, type Fund, type FundEntry, type FundEntryType } from '@/services/funds'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { thisMonth } from '@/utils/date'

/**
 * Fund > Funds: record money into, or out of, one fund (from / paid to,
 * remark). Given an `entry`, edits that row instead (its type stays).
 */
const props = defineProps<{ modelValue: boolean; fund: Fund | null; type: FundEntryType; entry?: FundEntry | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const accounts = ref<Account[]>([])
const accountOptions = computed(() => accounts.value.map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })))

const form = reactive({ amount: '', transacted_on: '', for_month: '', cash_account_id: '', party: '', provided_by: '', remark: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const editing = computed(() => props.entry ?? null)
const isOut = computed(() => (editing.value?.type ?? props.type) === 'out')
const money = (value: number) => formatMoney(value, props.fund?.currency ?? 'USD')
const title = computed(() => {
  if (editing.value) return isOut.value ? t('admin.funds.editMoneyOut') : t('admin.funds.editMoneyIn')
  return isOut.value ? t('admin.funds.moneyOutOf', { name: props.fund?.name ?? '—' }) : t('admin.funds.moneyInto', { name: props.fund?.name ?? '—' })
})
const sign = computed(() => (isOut.value ? -1 : 1))
// Editing: the saved amount comes off first, then the new one goes on.
const before = computed(() => (props.fund ? props.fund.balance - (editing.value ? sign.value * editing.value.amount : 0) : 0))
const after = computed(() => before.value + sign.value * Number(form.amount || 0))

watch(
  () => [props.modelValue, props.fund, props.entry] as const,
  async ([open]) => {
    if (!open) return
    const entry = editing.value
    if (entry) {
      Object.assign(form, {
        amount: String(entry.amount),
        transacted_on: entry.transacted_on,
        for_month: entry.for_month ?? entry.transacted_on.slice(0, 7),
        cash_account_id: entry.cash_account ? String(entry.cash_account.id) : '',
        // Money in from a picked provider / staff shows in the picker; typed names stay in Paid to.
        party: entry.party ?? '',
        provided_by: providedByValue(entry),
        remark: entry.remark ?? '',
      })
    } else {
      Object.assign(form, { amount: '', transacted_on: today(), for_month: thisMonth(), cash_account_id: form.cash_account_id, party: '', provided_by: '', remark: '' })
    }
    errors.value = {}
    saveError.value = null
    if (accounts.value.length === 0) {
      accounts.value = await accountsService
        .listAll()
        .then((all) => all.filter((a) => a.is_bank_or_cash && a.is_active))
        .catch(() => [])
      if (!form.cash_account_id && !entry && accounts.value.length === 1) form.cash_account_id = String(accounts.value[0]!.id)
    }
  },
  { immediate: true },
)

async function save() {
  if (!props.fund) return
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const input = {
      amount: Number(form.amount || 0),
      transacted_on: form.transacted_on,
      for_month: form.for_month || null,
      cash_account_id: Number(form.cash_account_id),
      // Money in: the provider picked; money out: who it was paid to.
      party: isOut.value ? form.party.trim() || null : null,
      // Money in: a staff member (HRM > Staff) or a provider (Fund > Providers).
      ...providedByIds(isOut.value ? '' : form.provided_by),
      remark: form.remark.trim() || null,
    }
    if (editing.value) {
      // Money in typed by name (no provider picked) keeps that name.
      if (!isOut.value && !form.provided_by) input.party = editing.value.party
      await fundsService.updateEntry(editing.value.id, input)
    } else {
      await fundsService.record(props.fund.id, { ...input, type: props.type })
    }
    emit('saved')
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.funds.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="title" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="fund" class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError || errors.status" variant="danger">{{ saveError || errors.status?.[0] }}</BaseAlert>
      <p class="text-sm text-neutral-600">
        {{ fund.fund_no }} · {{ t('admin.funds.balance') }}: <span class="font-semibold text-neutral-900">{{ money(before) }}</span>
      </p>
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.amount" type="number" required :label="t('admin.funds.amount')" :error="errors.amount?.[0]" />
        <BaseInput v-model="form.transacted_on" type="date" required :label="t('admin.funds.date')" :error="errors.transacted_on?.[0]" />
        <BaseInput v-model="form.for_month" type="month" :label="t('admin.funds.forMonth')" :error="errors.for_month?.[0]" />
      </div>
      <SearchableSelect
        v-model="form.cash_account_id"
        required
        :options="accountOptions"
        :label="isOut ? t('admin.funds.paidFrom') : t('admin.funds.receivedInto')"
        :placeholder="t('admin.funds.selectAccount')"
        :error="errors.cash_account_id?.[0]"
      />
      <BaseInput v-if="isOut" v-model="form.party" :label="t('admin.funds.paidTo')" :error="errors.party?.[0]" />
      <FundProviderPicker v-else v-model="form.provided_by" :error="errors.fund_provider_id?.[0] ?? errors.staff_id?.[0]" />
      <BaseInput v-model="form.remark" :label="t('admin.funds.remark')" :error="errors.remark?.[0]" />
      <p class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-700">
        {{ t('admin.funds.balanceAfter') }}:
        <span class="font-semibold" :class="after < 0 ? 'text-danger-600' : 'text-neutral-900'">{{ money(after) }}</span>
      </p>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="Number(form.amount || 0) <= 0 || !form.cash_account_id || after < 0" @click="save">
        {{ editing ? t('admin.funds.saveChanges') : isOut ? t('admin.funds.moneyOut') : t('admin.funds.moneyIn') }}
      </BaseButton>
    </template>
  </BaseModal>
</template>

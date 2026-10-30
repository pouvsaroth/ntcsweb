<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { accountsService, type Account } from '@/services/accounting'
import { incomeService, type IncomeInput } from '@/services/financialTransactions'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { thisMonth } from '@/utils/date'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()
const auth = useAuthStore()
/** New income starts on the school's own currency. */
const defaultCurrency = () => auth.tenantDefaultCurrency ?? 'USD'

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const form = reactive<IncomeInput>({ revenue_account_id: null, cash_account_id: null, amount: '', currency: defaultCurrency(), date: today(), for_month: thisMonth(), description: '' })

const currencyOptions = computed(() => [
  { value: 'USD', label: t('admin.coursePackages.currencyUsd') },
  { value: 'KHR', label: t('admin.coursePackages.currencyKhr') },
])

// For month follows the date until it is picked by hand
// (received today for September: pick September).
const monthPicked = ref(false)
watch(
  () => form.date,
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

const accounts = ref<Account[]>([])
const loadingAccounts = ref(false)

const revenueOptions = computed(() =>
  accounts.value.filter((a) => a.type === 'REVENUE' && a.is_active).map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })),
)
const cashOptions = computed(() =>
  accounts.value.filter((a) => a.is_bank_or_cash && a.is_active).map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })),
)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    form.revenue_account_id = null
    form.cash_account_id = null
    form.amount = ''
    form.currency = defaultCurrency()
    form.date = today()
    form.for_month = thisMonth()
    monthPicked.value = false
    form.description = ''
    errors.value = {}
    generalError.value = null
  },
)

onMounted(async () => {
  loadingAccounts.value = true
  try {
    accounts.value = await accountsService.listAll()
  } finally {
    loadingAccounts.value = false
  }
})

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await incomeService.create(form)
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.income.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.income.createTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <SearchableSelect
        :model-value="form.revenue_account_id !== null ? String(form.revenue_account_id) : ''"
        :options="revenueOptions"
        :disabled="loadingAccounts"
        required
        :label="t('admin.income.revenueAccount')"
        :error="errors.revenue_account_id?.[0]"
        @update:model-value="form.revenue_account_id = $event ? Number($event) : null"
      />

      <SearchableSelect
        :model-value="form.cash_account_id !== null ? String(form.cash_account_id) : ''"
        :options="cashOptions"
        :disabled="loadingAccounts"
        required
        :label="t('admin.income.cashAccount')"
        :error="errors.cash_account_id?.[0]"
        @update:model-value="form.cash_account_id = $event ? Number($event) : null"
      />

      <div class="grid grid-cols-2 gap-4">
        <BaseInput
          :model-value="form.amount"
          type="number"
          required
          :label="t('admin.income.amount')"
          :error="errors.amount?.[0]"
          @update:model-value="form.amount = $event"
        />
        <BaseSelect v-model="form.currency" :options="currencyOptions" required :label="t('admin.income.currency')" :error="errors.currency?.[0]" />
      </div>

      <div class="grid grid-cols-2 gap-4">
        <BaseInput v-model="form.date" type="date" :label="t('admin.income.date')" :error="errors.date?.[0]" />
        <BaseInput
          :model-value="form.for_month"
          type="month"
          required
          :label="t('admin.income.forMonth')"
          :error="errors.for_month?.[0]"
          @update:model-value="onMonth"
        />
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.income.description') }}</label>
        <textarea
          v-model="form.description"
          rows="2"
          class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

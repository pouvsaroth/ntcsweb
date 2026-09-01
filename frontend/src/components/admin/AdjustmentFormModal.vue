<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { accountsService, type Account } from '@/services/accounting'
import { financialTransactionsService, type AdjustmentInput } from '@/services/financialTransactions'
import { ApiRequestError } from '@/types/api'

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const form = reactive<AdjustmentInput>({ debit_account_id: null, credit_account_id: null, amount: '', date: today(), description: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const accounts = ref<Account[]>([])
const loadingAccounts = ref(false)

const accountOptions = computed(() =>
  accounts.value.filter((a) => a.is_active).map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })),
)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    form.debit_account_id = null
    form.credit_account_id = null
    form.amount = ''
    form.date = today()
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
    await financialTransactionsService.adjustment(form)
    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.transactions.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.transactions.newAdjustment')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <p class="text-xs text-neutral-500">{{ t('admin.transactions.adjustmentHint') }}</p>

      <BaseSelect
        :model-value="form.debit_account_id !== null ? String(form.debit_account_id) : ''"
        :options="accountOptions"
        :disabled="loadingAccounts"
        required
        :label="t('admin.transactions.debitAccount')"
        :error="errors.debit_account_id?.[0]"
        @update:model-value="form.debit_account_id = $event ? Number($event) : null"
      />

      <BaseSelect
        :model-value="form.credit_account_id !== null ? String(form.credit_account_id) : ''"
        :options="accountOptions"
        :disabled="loadingAccounts"
        required
        :label="t('admin.transactions.creditAccount')"
        :error="errors.credit_account_id?.[0]"
        @update:model-value="form.credit_account_id = $event ? Number($event) : null"
      />

      <div class="grid grid-cols-2 gap-4">
        <BaseInput
          :model-value="form.amount"
          type="number"
          required
          :label="t('admin.transactions.amount')"
          :error="errors.amount?.[0]"
          @update:model-value="form.amount = $event"
        />
        <BaseInput v-model="form.date" type="date" :label="t('admin.transactions.date')" />
      </div>

      <BaseInput v-model="form.description" required :label="t('admin.transactions.description')" :error="errors.description?.[0]" />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

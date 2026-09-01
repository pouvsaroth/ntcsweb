<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { accountsService, type Account } from '@/services/accounting'

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const props = defineProps<{ modelValue: boolean; submitting?: boolean; error?: string | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [cashAccountId: number, paidDate: string] }>()

const { t } = useI18n()

const cashAccountId = ref<number | null>(null)
const paidDate = ref(today())

const accounts = ref<Account[]>([])
const loadingAccounts = ref(false)

const cashOptions = computed(() =>
  accounts.value.filter((a) => a.is_bank_or_cash && a.is_active).map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })),
)

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      cashAccountId.value = null
      paidDate.value = today()
    }
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

function submit() {
  if (!cashAccountId.value) return
  emit('confirm', cashAccountId.value, paidDate.value)
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.expenses.payTitle')" size="sm" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>

      <BaseSelect
        :model-value="cashAccountId !== null ? String(cashAccountId) : ''"
        :options="cashOptions"
        :disabled="loadingAccounts"
        required
        :label="t('admin.expenses.cashAccount')"
        @update:model-value="cashAccountId = $event ? Number($event) : null"
      />

      <BaseInput v-model="paidDate" type="date" :label="t('admin.expenses.paidDate')" />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" :disabled="!cashAccountId" @click="submit">{{ t('admin.expenses.payTitle') }}</BaseButton>
    </template>
  </BaseModal>
</template>

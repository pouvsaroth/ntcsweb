<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { loanCustomersService, type LoanCustomer, type LoanCustomerGender, type LoanCustomerInput } from '@/services/loanCustomers'
import { ApiRequestError } from '@/types/api'

/** Loan > Customers: add or edit a customer (also opened from the Savings and Owe forms later). */
const props = defineProps<{ modelValue: boolean; customer?: LoanCustomer | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [customer: LoanCustomer] }>()

const { t } = useI18n()

const form = reactive<LoanCustomerInput>({ name: '', gender: '', phone: '', id_card_no: '', date_of_birth: '', address: '', notes: '', is_active: true })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const genderOptions = computed(() => [
  { value: '', label: '—' },
  { value: 'male', label: t('admin.loanCustomers.male') },
  { value: 'female', label: t('admin.loanCustomers.female') },
])

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const c = props.customer
    form.name = c?.name ?? ''
    form.gender = c?.gender ?? ''
    form.phone = c?.phone ?? ''
    form.id_card_no = c?.id_card_no ?? ''
    form.date_of_birth = c?.date_of_birth ?? ''
    form.address = c?.address ?? ''
    form.notes = c?.notes ?? ''
    form.is_active = c?.is_active ?? true
    errors.value = {}
    saveError.value = null
  },
  { immediate: true },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const saved = props.customer ? await loanCustomersService.update(props.customer.id, form) : await loanCustomersService.create(form)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.loanCustomers.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="customer ? t('admin.loanCustomers.edit') : t('admin.loanCustomers.add')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_9rem]">
        <BaseInput v-model="form.name" required :label="t('admin.loanCustomers.name')" :error="errors.name?.[0]" />
        <BaseSelect
          :model-value="form.gender"
          :options="genderOptions"
          :label="t('admin.loanCustomers.gender')"
          :error="errors.gender?.[0]"
          @update:model-value="form.gender = $event as LoanCustomerGender | ''"
        />
      </div>
      <div class="grid gap-4 sm:grid-cols-3">
        <BaseInput v-model="form.phone" :label="t('admin.loanCustomers.phone')" :error="errors.phone?.[0]" />
        <BaseInput v-model="form.id_card_no" :label="t('admin.loanCustomers.idCard')" :error="errors.id_card_no?.[0]" />
        <BaseInput v-model="form.date_of_birth" type="date" :label="t('admin.loanCustomers.dateOfBirth')" :error="errors.date_of_birth?.[0]" />
      </div>
      <BaseInput v-model="form.address" :label="t('admin.loanCustomers.address')" :error="errors.address?.[0]" />
      <BaseInput v-model="form.notes" :label="t('admin.loanCustomers.notes')" :error="errors.notes?.[0]" />
      <label v-if="customer" class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300" />
        {{ t('admin.loanCustomers.active') }}
      </label>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

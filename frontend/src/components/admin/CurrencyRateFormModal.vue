<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { currencyRatesService, type CurrencyRate } from '@/services/currencyRates'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when adding a new rate. */
  rate?: CurrencyRate | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const isEditing = computed(() => props.rate != null)

const form = reactive({
  effective_date: '',
  khr_per_usd: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => [props.modelValue, props.rate] as const,
  ([open]) => {
    if (!open) return

    form.effective_date = props.rate?.effective_date ?? ''
    form.khr_per_usd = props.rate ? String(props.rate.khr_per_usd) : ''
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const input = { effective_date: form.effective_date, khr_per_usd: Number(form.khr_per_usd) }

    if (isEditing.value) {
      await currencyRatesService.update(props.rate!.id, input)
    } else {
      await currencyRatesService.create(input)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.currencyRates.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.currencyRates.editTitle') : t('admin.currencyRates.createTitle')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput
        v-model="form.effective_date"
        type="date"
        required
        :label="t('admin.currencyRates.effectiveDate')"
        :error="errors.effective_date?.[0]"
      />

      <BaseInput
        v-model="form.khr_per_usd"
        type="number"
        step="0.0001"
        required
        :label="t('admin.currencyRates.khrPerUsd')"
        :hint="t('admin.currencyRates.khrPerUsdHint')"
        :error="errors.khr_per_usd?.[0]"
      />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

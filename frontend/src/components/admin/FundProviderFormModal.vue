<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import LookupSelect from '@/components/ui/LookupSelect.vue'
import { fundProvidersService, type FundProvider, type FundProviderInput } from '@/services/fundProviders'
import { ApiRequestError } from '@/types/api'

/** Fund > Providers: add or edit a provider (also opened from Money in with "+ New"). */
const props = defineProps<{ modelValue: boolean; provider?: FundProvider | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [provider: FundProvider] }>()

const { t } = useI18n()

const form = reactive<FundProviderInput>({ name: '', gender: '', phone: '', address: '', notes: '', is_active: true })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const p = props.provider
    form.name = p?.name ?? ''
    form.gender = p?.gender ?? ''
    form.phone = p?.phone ?? ''
    form.address = p?.address ?? ''
    form.notes = p?.notes ?? ''
    form.is_active = p?.is_active ?? true
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
    const saved = props.provider ? await fundProvidersService.update(props.provider.id, form) : await fundProvidersService.create(form)
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.fundProviders.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="provider ? t('admin.fundProviders.edit') : t('admin.fundProviders.add')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <BaseInput v-model="form.name" required :label="t('admin.fundProviders.name')" :error="errors.name?.[0]" />
      <div class="grid gap-4 sm:grid-cols-2">
        <LookupSelect
          v-model="form.gender"
          category="GENDER"
          :label="t('admin.fundProviders.gender')"
          :placeholder="t('admin.fundProviders.selectGender')"
          :error="errors.gender?.[0]"
        />
        <BaseInput v-model="form.phone" :label="t('admin.fundProviders.phone')" :error="errors.phone?.[0]" />
      </div>
      <BaseInput v-model="form.address" :label="t('admin.fundProviders.address')" :error="errors.address?.[0]" />
      <BaseInput v-model="form.notes" :label="t('admin.fundProviders.notes')" :error="errors.notes?.[0]" />
      <label v-if="provider" class="flex items-center gap-2 text-sm text-neutral-700">
        <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300" />
        {{ t('admin.fundProviders.active') }}
      </label>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!form.name.trim()" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

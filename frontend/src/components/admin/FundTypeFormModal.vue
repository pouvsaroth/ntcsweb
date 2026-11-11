<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { fundsService, type NewFundType } from '@/services/funds'
import { ApiRequestError } from '@/types/api'

/** Fund form "+ New fund type": adds a type to the Fund type list (Base Data FUND_TYPE). */
const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [type: NewFundType] }>()

const { t } = useI18n()

const form = reactive({ name: '', name_km: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    form.name = ''
    form.name_km = ''
    errors.value = {}
    saveError.value = null
  },
)

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const saved = await fundsService.addType({ name: form.name.trim(), name_km: form.name_km.trim() || null })
    emit('saved', saved)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.funds.typeSaveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.funds.newTypeTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <BaseInput v-model="form.name" required :label="t('admin.funds.typeNameEn')" :error="errors.name?.[0]" />
      <BaseInput v-model="form.name_km" :label="t('admin.funds.typeNameKm')" :hint="t('admin.funds.typeNameKmHint')" :error="errors.name_km?.[0]" />
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!form.name.trim()" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

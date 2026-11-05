<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { staffService, type Staff } from '@/services/staff'
import { ApiRequestError } from '@/types/api'

/**
 * Staff list > Signature: upload or replace one staff member's signature
 * without opening the whole Staff form. Printed on invoices, receipts and
 * the payslips they prepare or approve.
 */
const props = defineProps<{ modelValue: boolean; staff: Staff | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const file = ref<File | null>(null)
const preview = ref<string | null>(null)
const error = ref<string | null>(null)
const saving = ref(false)

watch(
  () => [props.modelValue, props.staff] as const,
  ([open]) => {
    if (!open) return
    file.value = null
    preview.value = props.staff?.signature_url ?? null
    error.value = null
  },
  { immediate: true },
)

function onFile(event: Event) {
  const picked = (event.target as HTMLInputElement).files?.[0]
  if (!picked) return
  file.value = picked
  preview.value = URL.createObjectURL(picked)
}

async function save() {
  if (!props.staff || !file.value) return
  saving.value = true
  error.value = null
  try {
    await staffService.uploadSignature(props.staff.id, file.value)
    emit('saved')
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (e.errors?.signature?.[0] ?? e.message) : t('admin.staff.signatureSaveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.staff.signatureTitle', { name: staff?.full_name ?? '' })" @update:model-value="emit('update:modelValue', $event)">
    <div class="space-y-4">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <p class="text-sm text-neutral-500">{{ t('admin.staff.signatureHint') }}</p>
      <div class="flex h-32 items-center justify-center overflow-hidden rounded-lg border border-dashed border-neutral-300 bg-neutral-50">
        <img v-if="preview" :src="preview" alt="" class="h-full w-full object-contain p-2" />
        <span v-else class="text-sm text-neutral-400">{{ t('admin.staff.noSignature') }}</span>
      </div>
      <input
        type="file"
        accept="image/png,image/jpeg,image/webp,image/gif"
        class="block w-full text-sm text-neutral-700 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100"
        @change="onFile"
      />
    </div>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!file" @click="save">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

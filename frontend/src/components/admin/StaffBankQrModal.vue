<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseModal from '@/components/ui/BaseModal.vue'
import { staffService, type Staff } from '@/services/staff'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Staff list: a staff member's bank KHQR, large enough to scan.
 * Only the staff member themselves can add, replace or remove it.
 */
const props = defineProps<{ modelValue: boolean; staff: Staff | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()
const auth = useAuthStore()

const qrUrl = ref<string | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)

const isOwn = computed(() => props.staff?.user?.id != null && props.staff.user.id === auth.user?.id)

watch(
  () => [props.modelValue, props.staff] as const,
  ([open, staff]) => {
    if (open) {
      qrUrl.value = staff?.bank_qr_url ?? null
      error.value = null
    }
  },
  { immediate: true },
)

async function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !props.staff) return
  busy.value = true
  error.value = null
  try {
    qrUrl.value = (await staffService.uploadBankQr(props.staff.id, file)).bank_qr_url
    emit('saved')
  } catch (err) {
    error.value = err instanceof ApiRequestError ? err.message : t('admin.staff.bankQrFailed')
  } finally {
    busy.value = false
  }
}

async function remove() {
  if (!props.staff) return
  busy.value = true
  error.value = null
  try {
    await staffService.removeBankQr(props.staff.id)
    qrUrl.value = null
    emit('saved')
  } catch (err) {
    error.value = err instanceof ApiRequestError ? err.message : t('admin.staff.bankQrFailed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.staff.bankQr')" size="sm" @update:model-value="emit('update:modelValue', $event)">
    <div class="space-y-4 text-center">
      <p v-if="staff" class="font-medium text-neutral-800">{{ staff.full_name }}</p>
      <div v-if="qrUrl" class="mx-auto flex aspect-square w-full max-w-xs items-center justify-center overflow-hidden rounded-lg border border-neutral-200 bg-white">
        <img :src="qrUrl" :alt="t('admin.staff.bankQr')" class="h-full w-full object-contain" />
      </div>
      <p v-else class="text-sm text-neutral-500">{{ t('admin.staff.bankQrNone') }}</p>
      <p class="text-xs text-neutral-500">{{ isOwn ? t('admin.staff.bankQrHintOwn') : t('admin.staff.bankQrHintOthers') }}</p>

      <div v-if="isOwn" class="flex flex-wrap items-center justify-center gap-3">
        <label
          class="cursor-pointer rounded-lg bg-primary-50 px-3 py-2 text-sm font-medium text-primary-800 hover:bg-primary-100"
          :class="{ 'pointer-events-none opacity-50': busy }"
        >
          {{ qrUrl ? t('admin.staff.bankQrReplace') : t('admin.staff.bankQrUpload') }}
          <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="busy" @change="onFileChange" />
        </label>
        <button v-if="qrUrl" type="button" class="text-sm font-medium text-danger-600 hover:underline disabled:opacity-50" :disabled="busy" @click="remove">
          {{ t('admin.staff.bankQrRemove') }}
        </button>
      </div>
      <p v-if="error" class="text-sm text-danger-600">{{ error }}</p>
    </div>
  </BaseModal>
</template>

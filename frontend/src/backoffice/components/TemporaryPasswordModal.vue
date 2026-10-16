<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'

/** Shows a temporary password once — the server never returns it again. */
const props = defineProps<{ modelValue: boolean; name: string; password: string }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean] }>()

const { t } = useI18n()
const copied = ref(false)

watch(
  () => props.modelValue,
  () => (copied.value = false),
)

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.password)
    copied.value = true
  } catch {
    // Clipboard blocked: it is on screen to copy by hand.
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('bo.staff.tempTitle')" size="sm" @update:model-value="emit('update:modelValue', $event)">
    <p class="text-sm text-neutral-600">{{ t('bo.staff.tempHint', { name }) }}</p>
    <p class="mt-4 rounded bg-neutral-100 px-3 py-2 text-center font-mono text-lg tracking-wide break-all text-neutral-900">{{ password }}</p>

    <template #footer>
      <BaseButton variant="outline" @click="copy">{{ copied ? t('bo.signIn.recoveryCopied') : t('bo.signIn.recoveryCopy') }}</BaseButton>
      <BaseButton @click="emit('update:modelValue', false)">{{ t('bo.common.close') }}</BaseButton>
    </template>
  </BaseModal>
</template>

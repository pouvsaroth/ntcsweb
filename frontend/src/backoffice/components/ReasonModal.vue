<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'

/** Asks why, for a status change that must record one (suspend, archive). */
const props = defineProps<{ modelValue: boolean; title: string; hint: string; confirmLabel: string; busy?: boolean; error?: string }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; confirm: [reason: string] }>()

const { t } = useI18n()
const reason = ref('')

watch(
  () => props.modelValue,
  (open) => open && (reason.value = ''),
)
</script>

<template>
  <BaseModal :model-value="modelValue" :title="title" size="sm" @update:model-value="emit('update:modelValue', $event)">
    <form id="bo-reason" class="space-y-3" @submit.prevent="emit('confirm', reason)">
      <p class="text-sm text-neutral-600">{{ hint }}</p>
      <BaseInput v-model="reason" :label="t('bo.schools.reason')" :error="error" required />
    </form>
    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="bo-reason" variant="danger" :loading="busy">{{ confirmLabel }}</BaseButton>
    </template>
  </BaseModal>
</template>

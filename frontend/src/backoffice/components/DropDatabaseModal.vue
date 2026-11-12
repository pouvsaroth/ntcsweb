<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { ApiRequestError } from '@/types/api'

import { dataApi, formatBytes, type PlatformDatabase } from '../api'

/** Delete an "Other" database (DROP DATABASE). Its name must be typed to confirm. No backup. */
const props = defineProps<{ modelValue: boolean; database: PlatformDatabase | null }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; dropped: [name: string] }>()

const { t } = useI18n()

const confirmText = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const canDrop = computed(() => !!props.database && confirmText.value === props.database.database)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    confirmText.value = ''
    error.value = null
  },
)

async function drop(): Promise<void> {
  if (!canDrop.value || !props.database?.extra) return
  busy.value = true
  error.value = null
  try {
    await dataApi.dropDatabase(props.database.extra.id, confirmText.value)
    emit('dropped', props.database.database)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : t('bo.common.failed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('bo.data.dropDb.title')" @update:model-value="emit('update:modelValue', $event)">
    <div v-if="database" class="space-y-4">
      <BaseAlert v-if="error" variant="danger">{{ error }}</BaseAlert>
      <BaseAlert variant="danger">{{ t('bo.data.dropDb.warning', { name: database.database, size: formatBytes(database.size_bytes) }) }}</BaseAlert>
      <BaseInput v-model="confirmText" :label="t('bo.data.dropDb.typeName', { name: database.database })" :placeholder="database.database" autocomplete="off" />
    </div>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton variant="danger" :disabled="!canDrop" :loading="busy" @click="drop">{{ t('bo.data.dropDb.confirm') }}</BaseButton>
    </template>
  </BaseModal>
</template>

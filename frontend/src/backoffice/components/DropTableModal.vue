<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'

import { tableDataApi, type TableRelationships } from '../api'

/**
 * Delete the table itself (DROP TABLE). Shows its rows and the tables that
 * point to it; when there are any, a tick removes those links (only the links,
 * the other tables stay). The name must be typed to confirm. No backup.
 */
const props = defineProps<{ modelValue: boolean; database: string; table: string }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; dropped: [table: string] }>()

const { t } = useI18n()

const info = ref<TableRelationships | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const cascade = ref(false)
const confirmText = ref('')

const needsCascade = computed(() => (info.value?.referenced_by.length ?? 0) > 0)
const canDrop = computed(() => confirmText.value === props.table && (!needsCascade.value || cascade.value) && !loading.value)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    info.value = null
    error.value = null
    cascade.value = false
    confirmText.value = ''
    loading.value = true
    try {
      info.value = await tableDataApi.relationships(props.database, props.table)
    } catch (e) {
      error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
    } finally {
      loading.value = false
    }
  },
)

async function drop(): Promise<void> {
  if (!canDrop.value) return
  busy.value = true
  error.value = null
  try {
    await tableDataApi.drop({ database: props.database, table: props.table, cascade: cascade.value, confirm: confirmText.value })
    emit('dropped', props.table)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('bo.data.tbl.dropTitle', { table })" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else-if="info">
      <BaseAlert variant="danger" class="mb-3">{{ t('bo.data.tbl.dropWarning', { table: info.table, n: info.rows.toLocaleString() }) }}</BaseAlert>
      <p class="mb-3 font-mono text-xs text-neutral-500">{{ database }}</p>
      <BaseAlert v-if="info.shared_schools" variant="warning" class="mb-3">{{ t('bo.data.tbl.dropSharedWarning', { n: info.shared_schools }) }}</BaseAlert>

      <section class="mb-4 rounded-lg border border-neutral-200 p-3">
        <h3 class="mb-2 text-xs font-semibold tracking-wide text-neutral-500 uppercase">{{ t('bo.data.tbl.pointedBy') }}</h3>
        <p v-if="info.referenced_by.length === 0" class="text-sm text-neutral-400">{{ t('bo.data.tbl.none') }}</p>
        <ul v-else class="space-y-1.5 text-sm">
          <li v-for="fk in info.referenced_by" :key="fk.name">
            <span class="font-mono text-neutral-800">{{ fk.from_table }}</span>
            <span class="text-xs text-neutral-500"> · {{ fk.rows.toLocaleString() }} · {{ t('bo.data.tbl.via', { cols: fk.from_columns.join(', ') }) }}</span>
          </li>
        </ul>
      </section>

      <template v-if="needsCascade">
        <p v-if="!cascade" class="mb-2 text-sm text-amber-700">{{ t('bo.data.tbl.dropCascadeNeeded') }}</p>
        <label class="mb-4 flex items-start gap-2 text-sm text-neutral-800">
          <input v-model="cascade" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-red-600 focus:ring-red-500" />
          {{ t('bo.data.tbl.dropCascadeLabel', { n: info.referenced_by.length }) }}
        </label>
      </template>

      <BaseInput v-model="confirmText" :label="t('bo.data.tbl.typeToConfirm', { table })" autocomplete="off" :disabled="busy" @keydown.enter="drop" />
    </template>

    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton variant="danger" :disabled="!canDrop" :loading="busy" @click="drop">{{ t('bo.data.tbl.dropNow') }}</BaseButton>
    </template>
  </BaseModal>
</template>

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
 * Clear all data of one table. First shows how it is linked: the tables it
 * points to, the tables that point to it, and — if those exist — every table
 * a cascade clear would empty too. The name must be typed to confirm.
 */
const props = defineProps<{ modelValue: boolean; database: string; table: string }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; cleared: [tables: string[], backupId: number | null] }>()

const { t } = useI18n()

const info = ref<TableRelationships | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const cascade = ref(false)
const backupFirst = ref(true)
const restartIds = ref(false)
const confirmText = ref('')

const needsCascade = computed(() => (info.value?.cascade.length ?? 0) > 0)
const canClear = computed(() => confirmText.value === props.table && (!needsCascade.value || cascade.value) && !loading.value)

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    info.value = null
    error.value = null
    cascade.value = false
    backupFirst.value = true
    restartIds.value = false
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

async function clear(): Promise<void> {
  if (!canClear.value) return
  busy.value = true
  error.value = null
  try {
    let state = await tableDataApi.clear({
      database: props.database,
      table: props.table,
      cascade: cascade.value,
      restart_ids: restartIds.value,
      backup_first: backupFirst.value,
      confirm: confirmText.value,
    })
    // Runs in the background (backup first, then clear): look every 1.5 s.
    while (state.status === 'running') {
      await new Promise((r) => setTimeout(r, 1500))
      state = await tableDataApi.clearStatus(state.token)
    }
    if (state.status !== 'done') {
      error.value = state.error ?? t('bo.common.failed')
      return
    }
    emit('cleared', state.cleared ?? [], state.backup_id ?? null)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('bo.data.tbl.clearTitle', { table })" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else-if="info">
      <p class="mb-3 text-sm text-neutral-700">
        {{ t('bo.data.tbl.clearRows', { table: info.table, n: info.rows.toLocaleString() }) }}
        <span class="font-mono text-xs text-neutral-500">· {{ database }}</span>
      </p>
      <BaseAlert v-if="info.shared_schools" variant="warning" class="mb-3">{{ t('bo.data.tbl.sharedWarning', { n: info.shared_schools }) }}</BaseAlert>

      <!-- Relationships -->
      <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <section class="rounded-lg border border-neutral-200 p-3">
          <h3 class="mb-2 text-xs font-semibold tracking-wide text-neutral-500 uppercase">{{ t('bo.data.tbl.pointsTo') }}</h3>
          <p v-if="info.references.length === 0" class="text-sm text-neutral-400">{{ t('bo.data.tbl.none') }}</p>
          <ul v-else class="space-y-1.5 text-sm">
            <li v-for="fk in info.references" :key="fk.name">
              <span class="font-mono text-neutral-800">{{ fk.to_table }}</span>
              <span class="text-xs text-neutral-500"> · {{ fk.rows.toLocaleString() }} · {{ t('bo.data.tbl.via', { cols: fk.from_columns.join(', ') }) }}</span>
            </li>
          </ul>
        </section>
        <section class="rounded-lg border border-neutral-200 p-3">
          <h3 class="mb-2 text-xs font-semibold tracking-wide text-neutral-500 uppercase">{{ t('bo.data.tbl.pointedBy') }}</h3>
          <p v-if="info.referenced_by.length === 0" class="text-sm text-neutral-400">{{ t('bo.data.tbl.none') }}</p>
          <ul v-else class="space-y-1.5 text-sm">
            <li v-for="fk in info.referenced_by" :key="fk.name">
              <span class="font-mono text-neutral-800">{{ fk.from_table }}</span>
              <span class="text-xs text-neutral-500">
                · {{ fk.rows.toLocaleString() }} · {{ t('bo.data.tbl.via', { cols: fk.from_columns.join(', ') }) }} · {{ t('bo.data.tbl.onDelete', { rule: fk.on_delete }) }}
              </span>
            </li>
          </ul>
        </section>
      </div>

      <!-- Cascade: every table that would be emptied too -->
      <template v-if="needsCascade">
        <p v-if="!cascade" class="mb-2 text-sm text-amber-700">{{ t('bo.data.tbl.cascadeNeeded') }}</p>
        <label class="mb-2 flex items-start gap-2 text-sm text-neutral-800">
          <input v-model="cascade" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-red-600 focus:ring-red-500" />
          {{ t('bo.data.tbl.cascadeLabel', { n: info.cascade.length }) }}
        </label>
        <div v-if="cascade" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
          <p class="mb-1.5 text-sm font-medium text-red-800">{{ t('bo.data.tbl.cascadeWarning') }}</p>
          <ul class="grid gap-x-4 gap-y-0.5 text-xs text-red-800 sm:grid-cols-2">
            <li v-for="c in info.cascade" :key="c.table">
              <span class="font-mono">{{ c.table }}</span> · {{ c.rows.toLocaleString() }}
            </li>
          </ul>
        </div>
      </template>

      <label class="mb-1.5 flex items-start gap-2 text-sm text-neutral-700">
        <input v-model="backupFirst" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('bo.data.tbl.backupFirst') }}
      </label>
      <label class="mb-4 flex items-start gap-2 text-sm text-neutral-700">
        <input v-model="restartIds" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
        {{ t('bo.data.tbl.restartIds') }}
      </label>

      <BaseInput v-model="confirmText" :label="t('bo.data.tbl.typeToConfirm', { table })" autocomplete="off" :disabled="busy" @keydown.enter="clear" />
      <p v-if="busy" class="mt-3 flex items-center gap-2 text-sm text-neutral-600">
        <BaseSpinner /> {{ backupFirst ? t('bo.data.tbl.clearingWithBackup') : t('bo.data.tbl.clearing') }}
      </p>
    </template>

    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton variant="danger" :disabled="!canClear" :loading="busy" @click="clear">{{ t('bo.data.tbl.clearNow') }}</BaseButton>
    </template>
  </BaseModal>
</template>

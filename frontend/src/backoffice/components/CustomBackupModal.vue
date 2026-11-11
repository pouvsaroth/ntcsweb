<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ApiRequestError } from '@/types/api'

import { dataApi, formatBytes, type BackupRow, type BackupTableMode, type DatabaseTable } from '../api'

/**
 * Custom backup: pick a database, tick the tables, and for each one keep its
 * rows ("Structure and data") or only its structure. Makes one .dump file.
 */
const props = defineProps<{ modelValue: boolean; databases: string[]; database?: string }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; started: [rows: BackupRow[]] }>()

const { t } = useI18n()

const database = ref('')
const tables = ref<DatabaseTable[]>([])
/** Ticked tables and the option chosen for each. */
const picked = ref<Record<string, BackupTableMode>>({})
const search = ref('')
const bulkMode = ref<BackupTableMode>('full')
const loading = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)

const databaseOptions = computed(() => [
  { value: '', label: t('bo.data.customPickDatabase') },
  ...props.databases.map((d) => ({ value: d, label: d })),
])
const modeOptions = computed(() => (['full', 'structure'] as const).map((m) => ({ value: m, label: t(`bo.data.tableModes.${m}`) })))

const shown = computed(() => {
  const q = search.value.trim().toLowerCase()
  return q ? tables.value.filter((tb) => tb.name.toLowerCase().includes(q)) : tables.value
})
const pickedCount = computed(() => Object.keys(picked.value).length)

function toggle(name: string, on: boolean): void {
  const next = { ...picked.value }
  if (on) next[name] = next[name] ?? bulkMode.value
  else delete next[name]
  picked.value = next
}

function setMode(name: string, mode: string): void {
  picked.value = { ...picked.value, [name]: mode as BackupTableMode }
}

/** Select all / Clear act on the tables shown (the search narrows them). */
function selectShown(on: boolean): void {
  const next = { ...picked.value }
  for (const tb of shown.value) {
    if (on) next[tb.name] = next[tb.name] ?? bulkMode.value
    else delete next[tb.name]
  }
  picked.value = next
}

function setAllTicked(mode: string): void {
  bulkMode.value = mode as BackupTableMode
  picked.value = Object.fromEntries(Object.keys(picked.value).map((name) => [name, bulkMode.value]))
}

async function loadTables(): Promise<void> {
  tables.value = []
  picked.value = {}
  error.value = null
  if (!database.value) return
  loading.value = true
  try {
    tables.value = await dataApi.tables(database.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
}

watch(database, () => void loadTables())

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    search.value = ''
    bulkMode.value = 'full'
    if (database.value === (props.database ?? '')) void loadTables()
    else database.value = props.database ?? ''
  },
)

async function start(): Promise<void> {
  if (!database.value || pickedCount.value === 0) return
  busy.value = true
  error.value = null
  try {
    // Keep the database's own table order in the file list.
    const body = tables.value.filter((tb) => picked.value[tb.name]).map((tb) => ({ name: tb.name, mode: picked.value[tb.name]! }))
    const rows = await dataApi.backupTables(database.value, body)
    emit('started', rows)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('bo.data.customBackup')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <p class="mb-4 text-sm text-neutral-500">{{ t('bo.data.customBackupHint') }}</p>

    <div class="mb-4">
      <BaseSelect v-model="database" :label="t('bo.data.database')" :options="databaseOptions" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <template v-else-if="database">
      <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-end">
        <div class="min-w-0 flex-1">
          <BaseInput v-model="search" :placeholder="t('bo.data.customSearch')" />
        </div>
        <div class="flex gap-2">
          <BaseButton size="sm" variant="outline" @click="selectShown(true)">{{ t('bo.data.customSelectAll') }}</BaseButton>
          <BaseButton size="sm" variant="ghost" @click="selectShown(false)">{{ t('bo.data.customSelectNone') }}</BaseButton>
        </div>
      </div>
      <div class="mb-3 flex flex-col gap-2 text-sm sm:flex-row sm:items-center sm:justify-between">
        <span class="text-neutral-500">{{ t('bo.data.customPicked', { n: pickedCount, total: tables.length }) }}</span>
        <div class="flex items-center gap-2">
          <span class="shrink-0 text-neutral-500">{{ t('bo.data.customSetAll') }}</span>
          <div class="w-48"><BaseSelect :model-value="bulkMode" :options="modeOptions" @update:model-value="setAllTicked" /></div>
        </div>
      </div>

      <p v-if="shown.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">
        {{ t('bo.data.customNoTables') }}
      </p>
      <ul v-else class="max-h-[50vh] divide-y divide-neutral-100 overflow-y-auto rounded-[--radius-card] border border-neutral-200">
        <li v-for="tb in shown" :key="tb.name" class="flex flex-col gap-2 p-2.5 sm:flex-row sm:items-center">
          <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-2">
            <input
              type="checkbox"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              :checked="!!picked[tb.name]"
              @change="toggle(tb.name, ($event.target as HTMLInputElement).checked)"
            />
            <span class="min-w-0 flex-1">
              <span class="block truncate font-mono text-sm text-neutral-800">{{ tb.name }}</span>
              <span class="text-xs text-neutral-500">{{ t('bo.data.customRows', { n: tb.rows.toLocaleString() }) }} · {{ formatBytes(tb.size_bytes) }}</span>
            </span>
          </label>
          <div v-if="picked[tb.name]" class="w-full pl-6 sm:w-48 sm:pl-0">
            <BaseSelect :model-value="picked[tb.name]" :options="modeOptions" @update:model-value="setMode(tb.name, $event)" />
          </div>
        </li>
      </ul>
    </template>

    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton :disabled="!database || pickedCount === 0" :loading="busy" @click="start">{{ t('bo.data.customStart', { n: pickedCount }) }}</BaseButton>
    </template>
  </BaseModal>
</template>

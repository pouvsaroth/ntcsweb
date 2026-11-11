<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

import { formatBytes, tableDataApi, type TableColumn, type TableRow } from '../api'
import { isDateType, toDb, toDisplay } from '../tableValues'

/**
 * One row of a table: every field, editable ones as boxes. Saves only the
 * fields that changed, found by the row's id (primary key).
 */
const props = defineProps<{ modelValue: boolean; database: string; table: string; columns: TableColumn[]; row: TableRow; canEdit: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; saved: [row: TableRow, changed: string[]] }>()

const { t } = useI18n()
const confirmDialog = useConfirmDialogStore()

/** Per field: the text in its box, and whether it is set to empty (NULL). */
const values = reactive<Record<string, string>>({})
const nulls = reactive<Record<string, boolean>>({})
const busy = ref(false)
const error = ref<string | null>(null)

/** Long text and JSON get a bigger box. */
const multiline = (col: TableColumn) => col.type === 'text' || col.type.startsWith('json') || col.type.endsWith('[]')

function load(): void {
  error.value = null
  for (const col of props.columns) {
    const v = props.row[col.name] ?? null
    nulls[col.name] = v === null
    values[col.name] = toDisplay(col, v) ?? ''
  }
}

watch(
  () => [props.modelValue, props.row] as const,
  ([open]) => {
    if (open) load()
  },
  { immediate: true },
)

const changes = computed<Record<string, string | null>>(() => {
  const out: Record<string, string | null> = {}
  for (const col of props.columns) {
    if (!col.editable) continue
    const before = props.row[col.name] ?? null
    const after = nulls[col.name] ? null : toDb(col, values[col.name] ?? '')
    if (after !== before) out[col.name] = after
  }
  return out
})
const changeCount = computed(() => Object.keys(changes.value).length)

function readOnlyReason(col: TableColumn): string {
  if (col.primary) return t('bo.data.tbl.readOnlyKey')
  if (col.secret) return t('bo.data.tbl.readOnlySecret')
  return t('bo.data.tbl.readOnly')
}

function shown(col: TableColumn): string {
  const v = props.row[col.name] ?? null
  if (v === null) return t('bo.data.tbl.empty')
  if (col.binary) return t('bo.data.tbl.binary', { size: formatBytes(Number(v)) })
  return toDisplay(col, v) ?? ''
}

async function save(): Promise<void> {
  if (changeCount.value === 0) {
    emit('update:modelValue', false)
    return
  }
  if (!(await confirmDialog.confirm({ message: t('bo.data.tbl.saveConfirm', { n: changeCount.value, table: props.table }) }))) return

  busy.value = true
  error.value = null
  try {
    const key = Object.fromEntries(props.columns.filter((c) => c.primary).map((c) => [c.name, props.row[c.name] ?? null]))
    const res = await tableDataApi.update(props.database, props.table, key, changes.value)
    emit('saved', res.row, res.changed)
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="`${t('bo.data.tbl.editRow')} · ${table}`" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="space-y-3">
      <div v-for="col in columns" :key="col.name">
        <div class="mb-1 flex flex-wrap items-baseline justify-between gap-x-2">
          <label :for="`f-${col.name}`" class="font-mono text-sm font-medium text-neutral-800">
            {{ col.name }}<span v-if="col.primary" class="ml-1 text-amber-600">🔑</span>
          </label>
          <span class="text-xs text-neutral-400">{{ col.type }}</span>
        </div>

        <template v-if="canEdit && col.editable">
          <textarea
            v-if="multiline(col)"
            :id="`f-${col.name}`"
            v-model="values[col.name]"
            rows="3"
            :disabled="nulls[col.name]"
            class="w-full rounded-lg border border-neutral-300 px-3 py-2 font-mono text-sm focus:border-primary-500 focus:ring-primary-500 disabled:bg-neutral-100 disabled:text-neutral-400"
          />
          <input
            v-else
            :id="`f-${col.name}`"
            v-model="values[col.name]"
            type="text"
            :disabled="nulls[col.name]"
            :placeholder="isDateType(col.type) ? 'dd-mm-yyyy' : ''"
            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500 disabled:bg-neutral-100 disabled:text-neutral-400"
          />
          <label v-if="col.nullable" class="mt-1 inline-flex items-center gap-1.5 text-xs text-neutral-500">
            <input v-model="nulls[col.name]" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('bo.data.tbl.setEmpty') }}
          </label>
        </template>
        <template v-else>
          <p class="rounded-lg bg-neutral-50 px-3 py-2 text-sm break-all whitespace-pre-wrap text-neutral-700">{{ shown(col) }}</p>
          <p v-if="canEdit" class="mt-0.5 text-xs text-neutral-400">{{ readOnlyReason(col) }}</p>
        </template>
      </div>
    </div>

    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton v-if="canEdit" :loading="busy" :disabled="changeCount === 0" @click="save">{{ t('bo.data.tbl.save') }}{{ changeCount ? ` (${changeCount})` : '' }}</BaseButton>
    </template>
  </BaseModal>
</template>

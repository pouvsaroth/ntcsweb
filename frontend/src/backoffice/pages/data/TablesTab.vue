<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import type { LengthAwarePaginationMeta } from '@/types/api'
import { ApiRequestError } from '@/types/api'

import {
  dataApi,
  formatBytes,
  tableDataApi,
  type ConditionOperator,
  type DatabaseTable,
  type TableColumn,
  type TableCondition,
  type TableRow,
  type TableRowsPage,
} from '../../api'
import ClearTableModal from '../../components/ClearTableModal.vue'
import DropTableModal from '../../components/DropTableModal.vue'
import TableRowEditModal from '../../components/TableRowEditModal.vue'
import { useSession } from '../../session'
import { isDateType, toDb, toDisplay } from '../../tableValues'

/**
 * Data ops > Tables: pick a database and a table, narrow the rows with
 * conditions (every one must match), edit one row, or clear the table.
 */
const { t } = useI18n()
const session = useSession()
const canEdit = computed(() => session.can('platform.data.edit'))

const databases = ref<string[]>([])
const database = ref('')
const tables = ref<DatabaseTable[]>([])
const table = ref('')

const result = ref<TableRowsPage | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

interface DraftCondition {
  column: string
  operator: ConditionOperator
  value: string
}
const drafts = ref<DraftCondition[]>([])
/** The conditions the rows on screen were fetched with. */
const applied = ref<TableCondition[]>([])
const sort = ref<string | undefined>(undefined)
const direction = ref<'asc' | 'desc'>('asc')
const page = ref(1)
const perPage = 50

const columns = computed<TableColumn[]>(() => result.value?.columns ?? [])
const byName = computed(() => Object.fromEntries(columns.value.map((c) => [c.name, c])))
const primary = computed(() => columns.value.filter((c) => c.primary).map((c) => c.name))

const databaseOptions = computed(() => [{ value: '', label: t('bo.data.tbl.pickDatabase') }, ...databases.value.map((d) => ({ value: d, label: d }))])
const tableOptions = computed(() => tables.value.map((tb) => ({ value: tb.name, label: tb.name, hint: `~${tb.rows.toLocaleString()} · ${formatBytes(tb.size_bytes)}` })))
const fieldOptions = computed(() => columns.value.filter((c) => !c.secret && !c.binary).map((c) => ({ value: c.name, label: `${c.name} (${c.type})` })))
const operatorOptions = computed(() =>
  (['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'not_contains', 'starts', 'ends', 'null', 'not_null'] as const).map((o) => ({
    value: o,
    label: t(`bo.data.tbl.operators.${o}`),
  })),
)
const noValue = (op: ConditionOperator) => op === 'null' || op === 'not_null'

const meta = computed<LengthAwarePaginationMeta | null>(() => {
  if (!result.value) return null
  const { total, page: current, per_page } = result.value
  const last = Math.max(1, Math.ceil(total / per_page))
  return {
    type: 'length_aware',
    current_page: current,
    per_page,
    total,
    last_page: last,
    from: total === 0 ? null : (current - 1) * per_page + 1,
    to: total === 0 ? null : Math.min(current * per_page, total),
  }
})

/** Phone cards: the id and the first few fields. */
const cardColumns = computed(() => columns.value.slice(0, 6))

async function loadTables(): Promise<void> {
  tables.value = []
  table.value = ''
  result.value = null
  if (!database.value) return
  try {
    tables.value = await dataApi.tables(database.value)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

async function fetchRows(): Promise<void> {
  if (!database.value || !table.value) return
  loading.value = true
  error.value = null
  try {
    result.value = await tableDataApi.rows({
      database: database.value,
      table: table.value,
      conditions: applied.value,
      sort: sort.value,
      direction: direction.value,
      page: page.value,
      per_page: perPage,
    })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
}

function addCondition(): void {
  drafts.value.push({ column: fieldOptions.value[0]?.value ?? '', operator: 'eq', value: '' })
}

function apply(): void {
  applied.value = drafts.value
    .filter((d) => d.column)
    .map((d) => ({ column: d.column, operator: d.operator, value: noValue(d.operator) ? null : toDb(byName.value[d.column], d.value) }))
  page.value = 1
  void fetchRows()
}

function reset(): void {
  drafts.value = []
  applied.value = []
  sort.value = undefined
  page.value = 1
  void fetchRows()
}

function sortBy(name: string): void {
  if (sort.value === name) direction.value = direction.value === 'asc' ? 'desc' : 'asc'
  else {
    sort.value = name
    direction.value = 'asc'
  }
  void fetchRows()
}

function setPage(p: number): void {
  page.value = p
  void fetchRows()
}

function cell(name: string, row: TableRow): string {
  const col = byName.value[name]
  const value = row[name] ?? null
  if (value === null) return ''
  if (col?.binary) return t('bo.data.tbl.binary', { size: formatBytes(Number(value)) })
  return toDisplay(col, value) ?? ''
}

// ── edit one row ───────────────────────────────────────────────────────────
const editing = ref<TableRow | null>(null)
const editOpen = ref(false)
function edit(row: TableRow): void {
  editing.value = row
  editOpen.value = true
}
function onSaved(row: TableRow, changed: string[]): void {
  notice.value = changed.length ? t('bo.data.tbl.saved', { fields: changed.join(', ') }) : t('bo.data.tbl.nothingChanged')
  if (!result.value) return
  const keyOf = (r: TableRow) => primary.value.map((k) => r[k]).join('\u0000')
  result.value.rows = result.value.rows.map((r) => (keyOf(r) === keyOf(row) ? row : r))
}

// ── clear the table ────────────────────────────────────────────────────────
const clearOpen = ref(false)
function onCleared(cleared: string[], backupId: number | null): void {
  notice.value = t('bo.data.tbl.cleared', { tables: cleared.join(', ') }) + (backupId ? ' ' + t('bo.data.tbl.clearedBackup') : '')
  page.value = 1
  void fetchRows()
}

// ── delete the table itself ────────────────────────────────────────────────
const dropOpen = ref(false)
async function onDropped(name: string): Promise<void> {
  await loadTables()
  notice.value = t('bo.data.tbl.dropped', { table: name })
}

watch(database, () => {
  notice.value = null
  void loadTables()
})
watch(table, () => {
  drafts.value = []
  applied.value = []
  sort.value = undefined
  page.value = 1
  notice.value = null
  result.value = null
  void fetchRows()
})

onMounted(async () => {
  databases.value = (await dataApi.databases()).filter((d) => d.exists && d.kind !== 'template').map((d) => d.database)
})
</script>

<template>
  <div>
    <div class="mb-4 grid gap-3 sm:grid-cols-2">
      <BaseSelect v-model="database" :label="t('bo.data.database')" :options="databaseOptions" />
      <SearchableSelect v-model="table" :label="t('bo.data.tbl.table')" :options="tableOptions" :placeholder="t('bo.data.tbl.pickTable')" :disabled="!database" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="success" class="mb-4">{{ notice }}</BaseAlert>

    <template v-if="table && result">
      <!-- Conditions -->
      <section class="mb-4 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="mb-3 flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
          <div>
            <h2 class="text-sm font-semibold text-neutral-800">{{ t('bo.data.tbl.conditions') }}</h2>
            <p class="text-xs text-neutral-500">{{ t('bo.data.tbl.allMustMatch') }}</p>
          </div>
          <div v-if="canEdit" class="flex flex-wrap gap-2">
            <BaseButton size="sm" variant="danger" @click="clearOpen = true">{{ t('bo.data.tbl.clear') }}</BaseButton>
            <BaseButton size="sm" variant="danger" @click="dropOpen = true">{{ t('bo.data.tbl.drop') }}</BaseButton>
          </div>
        </div>

        <div v-for="(d, i) in drafts" :key="i" class="mb-2 grid gap-2 rounded-lg bg-neutral-50 p-2 sm:grid-cols-[1fr_12rem_1fr_auto] sm:items-start sm:bg-transparent sm:p-0">
          <BaseSelect v-model="d.column" :options="fieldOptions" :aria-label="t('bo.data.tbl.field')" />
          <BaseSelect v-model="d.operator" :options="operatorOptions" :aria-label="t('bo.data.tbl.operator')" />
          <BaseInput
            v-if="!noValue(d.operator)"
            v-model="d.value"
            :placeholder="t('bo.data.tbl.value')"
            :hint="byName[d.column] && isDateType(byName[d.column]!.type) ? t('bo.data.tbl.dateHint') : undefined"
            @keydown.enter="apply"
          />
          <span v-else class="hidden sm:block" />
          <BaseButton size="sm" variant="ghost" :aria-label="t('bo.data.tbl.removeCondition')" @click="drafts.splice(i, 1)">✕</BaseButton>
        </div>

        <div class="mt-2 flex flex-wrap gap-2">
          <BaseButton size="sm" variant="outline" @click="addCondition">+ {{ t('bo.data.tbl.addCondition') }}</BaseButton>
          <BaseButton size="sm" @click="apply">{{ t('bo.data.tbl.apply') }}</BaseButton>
          <BaseButton v-if="drafts.length || applied.length || sort" size="sm" variant="ghost" @click="reset">{{ t('bo.data.tbl.reset') }}</BaseButton>
        </div>
      </section>

      <div class="mb-2 flex items-center justify-between text-sm text-neutral-500">
        <span>{{ t('bo.data.tbl.rowsFound', { n: result.total.toLocaleString() }) }}</span>
        <BaseSpinner v-if="loading" />
      </div>
      <p v-if="canEdit && primary.length === 0" class="mb-2 text-xs text-amber-700">{{ t('bo.data.tbl.noPrimaryKey') }}</p>

      <p v-if="result.rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.data.tbl.noRows') }}
      </p>
      <template v-else>
        <!-- Phone: cards -->
        <div class="space-y-2 sm:hidden">
          <div v-for="(row, ri) in result.rows" :key="ri" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
              <template v-for="col in cardColumns" :key="col.name">
                <dt class="font-mono text-neutral-500">{{ col.name }}</dt>
                <dd class="min-w-0 break-all text-neutral-800">
                  <span v-if="row[col.name] === null" class="text-neutral-400 italic">{{ t('bo.data.tbl.empty') }}</span>
                  <template v-else>{{ cell(col.name, row) }}</template>
                </dd>
              </template>
            </dl>
            <div class="mt-2 border-t border-neutral-100 pt-2">
              <BaseButton size="sm" variant="outline" @click="edit(row)">{{ canEdit && primary.length ? t('bo.data.tbl.edit') : t('bo.data.tbl.readOnly') }}</BaseButton>
            </div>
          </div>
        </div>

        <!-- Tablet / desktop: table -->
        <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
          <table class="min-w-full text-left text-xs">
            <thead class="bg-neutral-50 text-neutral-600">
              <tr>
                <th class="sticky left-0 bg-neutral-50 px-2 py-2" />
                <th v-for="col in columns" :key="col.name" class="px-3 py-2 font-medium whitespace-nowrap">
                  <button type="button" class="inline-flex items-center gap-1 font-mono hover:text-neutral-900" :title="col.type" @click="sortBy(col.name)">
                    {{ col.name }}
                    <span v-if="col.primary" class="text-amber-600">🔑</span>
                    <span v-if="sort === col.name">{{ direction === 'asc' ? '▲' : '▼' }}</span>
                  </button>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(row, ri) in result.rows" :key="ri" class="hover:bg-neutral-50">
                <td class="sticky left-0 bg-white px-2 py-1.5">
                  <BaseButton size="sm" variant="ghost" @click="edit(row)">{{ canEdit && primary.length ? t('bo.data.tbl.edit') : '…' }}</BaseButton>
                </td>
                <td v-for="col in columns" :key="col.name" class="max-w-[16rem] truncate px-3 py-1.5 text-neutral-800" :title="cell(col.name, row)">
                  <span v-if="row[col.name] === null" class="text-neutral-400 italic">{{ t('bo.data.tbl.empty') }}</span>
                  <template v-else>{{ cell(col.name, row) }}</template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />
      </template>
    </template>

    <div v-else-if="table && loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <TableRowEditModal
      v-if="editing && result"
      v-model="editOpen"
      :database="database"
      :table="table"
      :columns="result.columns"
      :row="editing"
      :can-edit="canEdit && primary.length > 0"
      @saved="onSaved"
    />
    <ClearTableModal v-if="table" v-model="clearOpen" :database="database" :table="table" @cleared="onCleared" />
    <DropTableModal v-if="table" v-model="dropOpen" :database="database" :table="table" @dropped="onDropped" />
  </div>
</template>

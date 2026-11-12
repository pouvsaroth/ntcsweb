<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import { dataApi, formatBytes, type BackupRow } from '../../api'
import BackupScheduleCard from '../../components/BackupScheduleCard.vue'
import CustomBackupModal from '../../components/CustomBackupModal.vue'
import RestoreDatabaseModal from '../../components/RestoreDatabaseModal.vue'
import { useSession } from '../../session'

/** Every backup run, newest first; download / delete; the schedule beside it. Refreshes while one is running. */
const { t } = useI18n()
const session = useSession()
const confirmDialog = useConfirmDialogStore()
const canBackup = computed(() => session.can('platform.data.backup'))
const canRestore = computed(() => session.can('platform.data.databases'))

const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<BackupRow>((query) => dataApi.backups(query))

const databases = ref<string[]>([])
const database = ref('')
const databaseOptions = computed(() => [{ value: '', label: t('bo.data.allDatabases') }, ...databases.value.map((d) => ({ value: d, label: d }))])

function onDatabase(value: string): void {
  database.value = value
  setFilter('database', value || undefined)
}

const statusVariant = { running: 'primary', ok: 'success', failed: 'danger' } as const

const columns = computed(() => [
  { key: 'created_at', label: t('bo.data.when') },
  { key: 'database', label: t('bo.data.database') },
  { key: 'trigger', label: t('bo.data.trigger') },
  { key: 'status', label: t('bo.data.status') },
  { key: 'size_bytes', label: t('bo.data.size'), align: 'text-right' },
  { key: 'actions', label: '', align: 'text-right' },
])

const actionError = ref<string | null>(null)

/** Custom backup: only the tables picked. Starts on the database the list is filtered to. */
const customOpen = ref(false)

/** Hover text on a custom backup: each table and its option. */
function tablesTitle(row: BackupRow): string {
  return (row.tables ?? []).map((tb) => `${tb.name} — ${t(`bo.data.tableModes.${tb.mode}`)}`).join('\n')
}

/** Restore: a finished database backup (not a school .zip) into a new database. */
const restoreOpen = ref(false)
const restoring = ref<BackupRow | null>(null)
const restoreMessage = ref<string | null>(null)
const canRestoreRow = (row: BackupRow) => canRestore.value && row.status === 'ok' && row.format !== 'school' && !!row.file

function openRestore(row: BackupRow): void {
  restoring.value = row
  restoreOpen.value = true
}

async function remove(row: BackupRow): Promise<void> {
  if (!(await confirmDialog.confirm({ message: t('bo.data.removeConfirm', { db: row.database }), danger: true }))) return
  try {
    await dataApi.removeBackup(row.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

// While any run on this page is still running, look again every few seconds.
let timer: ReturnType<typeof setInterval> | undefined
watch(items, (rows) => {
  clearInterval(timer)
  if (rows.some((r) => r.status === 'running')) timer = setInterval(() => void fetch(), 3000)
})
onBeforeUnmount(() => clearInterval(timer))

onMounted(async () => {
  void fetch()
  databases.value = (await dataApi.databases()).filter((d) => d.exists).map((d) => d.database)
})
</script>

<template>
  <div class="grid gap-6 2xl:grid-cols-3">
    <div class="min-w-0 2xl:col-span-2">
      <BaseAlert v-if="restoreMessage" variant="success" class="mb-4">{{ restoreMessage }}</BaseAlert>
      <div class="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div class="w-full sm:w-64">
          <BaseSelect :model-value="database" :options="databaseOptions" @update:model-value="onDatabase" />
        </div>
        <div class="flex gap-2">
          <BaseButton v-if="canBackup" size="sm" variant="outline" @click="customOpen = true">{{ t('bo.data.customBackup') }}</BaseButton>
          <BaseButton variant="ghost" size="sm" @click="fetch">{{ t('bo.data.refresh') }}</BaseButton>
        </div>
      </div>

      <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
      <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

      <!-- Phone: cards -->
      <div class="sm:hidden">
        <div v-if="loading && !items.length" class="flex justify-center py-10"><BaseSpinner /></div>
        <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
          {{ t('bo.data.emptyBackups') }}
        </p>
        <div v-else class="space-y-2">
          <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-mono text-sm break-all text-neutral-900">{{ row.database }}</p>
                <p v-if="row.format === 'school'" class="text-xs text-neutral-500">{{ t('bo.data.schoolExport') }}{{ row.school ? ` · ${row.school.name}` : '' }}</p>
                <p v-if="row.format === 'tables'" class="text-xs text-neutral-500" :title="tablesTitle(row)">{{ t('bo.data.customBackupFormat', { n: row.tables?.length ?? 0 }) }}</p>
              </div>
              <BaseBadge :variant="statusVariant[row.status]">{{ t(`bo.data.statuses.${row.status}`) }}</BaseBadge>
            </div>
            <p class="mt-1 text-xs text-neutral-500">
              {{ formatDateTime(row.created_at) }} · {{ t(`bo.data.triggers.${row.trigger}`) }}{{ row.staff ? ` · ${row.staff}` : '' }}
            </p>
            <p v-if="row.status === 'ok'" class="mt-1 text-xs text-neutral-600">
              {{ formatBytes(row.size_bytes) }} · {{ row.copied ? t('bo.data.copied') : t('bo.data.notCopied') }}
            </p>
            <p v-if="row.error || row.copy_error" class="mt-1 font-mono text-xs break-all text-red-700">{{ row.error ?? row.copy_error }}</p>
            <div v-if="canBackup && row.status !== 'running'" class="mt-3 flex gap-2 border-t border-neutral-100 pt-3">
              <BaseButton v-if="row.status === 'ok'" size="sm" variant="outline" :href="dataApi.downloadUrl(row.id)">{{ t('bo.data.download') }}</BaseButton>
              <BaseButton v-if="canRestoreRow(row)" size="sm" variant="ghost" @click="openRestore(row)">{{ t('bo.data.restoreDb.button') }}</BaseButton>
              <BaseButton size="sm" variant="ghost" @click="remove(row)">{{ t('bo.data.remove') }}</BaseButton>
            </div>
          </div>
        </div>
      </div>

      <!-- Tablet / desktop: table -->
      <div class="hidden sm:block">
        <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading && !items.length" :empty-message="t('bo.data.emptyBackups')">
          <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ formatDateTime(row.created_at) }}</span></template>
          <template #cell-database="{ row }">
            <span class="font-mono text-xs">{{ row.database }}</span>
            <p v-if="row.format === 'school'" class="text-xs text-neutral-500">{{ t('bo.data.schoolExport') }}{{ row.school ? ` · ${row.school.name}` : '' }}</p>
            <p v-if="row.format === 'tables'" class="text-xs text-neutral-500" :title="tablesTitle(row as BackupRow)">{{ t('bo.data.customBackupFormat', { n: (row as BackupRow).tables?.length ?? 0 }) }}</p>
            <p v-if="row.error" class="mt-1 max-w-xs font-mono text-xs break-all text-red-700">{{ row.error }}</p>
          </template>
          <template #cell-trigger="{ row }">
            {{ t(`bo.data.triggers.${row.trigger}`) }}
            <p v-if="row.staff" class="text-xs text-neutral-500">{{ row.staff }}</p>
          </template>
          <template #cell-status="{ row }">
            <BaseBadge :variant="statusVariant[row.status as BackupRow['status']]">{{ t(`bo.data.statuses.${row.status}`) }}</BaseBadge>
          </template>
          <template #cell-size_bytes="{ row }">
            <span class="tabular-nums">{{ formatBytes(row.size_bytes) }}</span>
            <!-- The second copy, under the size: copied, or why not. -->
            <p v-if="row.status === 'ok'" class="mt-0.5">
              <BaseBadge :variant="row.copied ? 'success' : 'warning'" :title="row.copy_error ?? ''">
                {{ row.copied ? t('bo.data.copied') : t('bo.data.notCopied') }}
              </BaseBadge>
            </p>
          </template>
          <template #cell-actions="{ row }">
            <div v-if="canBackup && row.status !== 'running'" class="flex flex-col items-end gap-1">
              <BaseButton v-if="row.status === 'ok'" size="sm" variant="ghost" :href="dataApi.downloadUrl(row.id)">{{ t('bo.data.download') }}</BaseButton>
              <BaseButton v-if="canRestoreRow(row as BackupRow)" size="sm" variant="ghost" @click="openRestore(row as BackupRow)">{{ t('bo.data.restoreDb.button') }}</BaseButton>
              <BaseButton size="sm" variant="ghost" @click="remove(row)">{{ t('bo.data.remove') }}</BaseButton>
            </div>
          </template>
        </DataTable>
      </div>

      <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />
      <p class="mt-4 text-xs text-neutral-500">{{ t('bo.data.restoreNote') }}</p>
    </div>

    <div>
      <BackupScheduleCard :can-edit="canBackup" />
    </div>

    <CustomBackupModal v-model="customOpen" :databases="databases" :database="database" @started="fetch" />
    <RestoreDatabaseModal v-model="restoreOpen" :backup="restoring" @restored="restoreMessage = t('bo.data.restoreDb.queued', { name: $event })" />
  </div>
</template>

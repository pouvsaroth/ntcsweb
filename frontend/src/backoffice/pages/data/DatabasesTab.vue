<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import DropDatabaseModal from '../../components/DropDatabaseModal.vue'
import NewDatabaseModal from '../../components/NewDatabaseModal.vue'
import { dataApi, formatBytes, type DatabaseFlag, type PlatformDatabase } from '../../api'
import { useSession } from '../../session'

/**
 * Every database the platform owns, probed on load, with what needs
 * attention flagged — then the "Other" databases staff made here (New
 * database: empty, template, copy), which can be deleted.
 */
const { t } = useI18n()
const session = useSession()
const confirmDialog = useConfirmDialogStore()

const rows = ref<PlatformDatabase[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const message = ref<string | null>(null)
const busy = ref<string | null>(null)

const canBackup = computed(() => session.can('platform.data.backup'))
const canMigrate = computed(() => session.can('platform.data.migrate'))
const canDatabases = computed(() => session.can('platform.data.databases'))

// New database / copy one / delete an "Other" one.
const newOpen = ref(false)
const copyFrom = ref<string | null>(null)
const dropOpen = ref(false)
const dropping = ref<PlatformDatabase | null>(null)

function openNew(from: string | null = null): void {
  copyFrom.value = from
  newOpen.value = true
}

function openDrop(row: PlatformDatabase): void {
  dropping.value = row
  dropOpen.value = true
}

async function onCreated(name: string): Promise<void> {
  message.value = t('bo.data.newDb.queued', { name })
  await load()
}

async function onDropped(name: string): Promise<void> {
  message.value = t('bo.data.dropDb.done', { name })
  await load()
}

const isExtra = (row: PlatformDatabase) => row.kind === 'extra' && !!row.extra
const canCopyRow = (row: PlatformDatabase) => canDatabases.value && row.exists && !row.server && (!isExtra(row) || row.extra!.status === 'ready')
const canDropRow = (row: PlatformDatabase) => canDatabases.value && isExtra(row) && row.extra!.status !== 'creating'
const extraStatusVariant = { creating: 'warning', ready: 'success', failed: 'danger' } as const

// While one is being made, look again every few seconds.
let poll: ReturnType<typeof setTimeout> | undefined
function schedulePoll(): void {
  clearTimeout(poll)
  if (rows.value.some((r) => r.extra?.status === 'creating')) poll = setTimeout(() => void load(true), 4000)
}
onBeforeUnmount(() => clearTimeout(poll))

const flagVariant: Record<DatabaseFlag, 'danger' | 'warning'> = {
  missing: 'danger',
  unreachable: 'danger',
  behind: 'warning',
  no_recent_backup: 'warning',
}

const columns = computed(() => [
  { key: 'database', label: t('bo.data.database') },
  { key: 'size_bytes', label: t('bo.data.size'), align: 'text-right' },
  { key: 'last_backup_at', label: t('bo.data.lastBackup') },
  { key: 'flags', label: t('bo.data.state') },
  { key: 'actions', label: '', align: 'text-right' },
])

async function load(quiet = false): Promise<void> {
  if (!quiet) loading.value = true
  error.value = null
  try {
    rows.value = await dataApi.databases()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
    schedulePoll()
  }
}

async function act(key: string, action: () => Promise<unknown>, done: string): Promise<void> {
  busy.value = key
  message.value = null
  error.value = null
  try {
    await action()
    message.value = done
  } catch (e) {
    error.value = e instanceof ApiRequestError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : t('bo.common.failed')
  } finally {
    busy.value = null
  }
}

const backup = (db: string) => act(db, () => dataApi.backupNow(db), t('bo.data.backupQueued'))
// One school's own rows out of the shared database.
const exportSchool = (id: number) => act(`school-${id}`, () => dataApi.backupSchool(id), t('bo.data.backupQueued'))

const kindVariant = { central: 'neutral', template: 'neutral', shared: 'primary', school: 'success', extra: 'warning' } as const
// The template is only copied from — never backed up; an "Other" one only once it is ready.
const canBackupRow = (row: PlatformDatabase) => canBackup.value && row.exists && row.kind !== 'template' && (!isExtra(row) || row.extra!.status === 'ready')
const hasActions = (row: PlatformDatabase) =>
  canBackupRow(row) || canCopyRow(row) || canDropRow(row) || (canMigrate.value && !!row.school && (row.exists || row.flags.includes('missing')))

async function migrate(row: PlatformDatabase): Promise<void> {
  if (!row.school) return
  await act(row.database, () => dataApi.migrate(row.school!.id), t('bo.data.migrateQueued', { n: 1 }))
}

async function createDatabase(row: PlatformDatabase): Promise<void> {
  if (!row.school || !(await confirmDialog.confirm(t('bo.data.createDatabaseConfirm', { name: row.school.name })))) return
  await act(row.database, () => dataApi.createDatabase(row.school!.id), t('bo.data.createQueued'))
}

async function migrateAll(): Promise<void> {
  if (!(await confirmDialog.confirm(t('bo.data.migrateAllConfirm')))) return
  busy.value = 'all'
  try {
    const result = await dataApi.migrate('all')
    message.value = t('bo.data.migrateQueued', { n: result.count })
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    busy.value = null
  }
}

onMounted(() => void load())
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap justify-end gap-2">
      <BaseButton variant="ghost" size="sm" :loading="loading" @click="load()">{{ t('bo.data.refresh') }}</BaseButton>
      <BaseButton v-if="canDatabases" variant="outline" size="sm" @click="openNew()">{{ t('bo.data.newDb.button') }}</BaseButton>
      <BaseButton v-if="canMigrate" variant="outline" size="sm" :loading="busy === 'all'" @click="migrateAll">{{ t('bo.data.migrateAll') }}</BaseButton>
      <BaseButton v-if="canBackup" size="sm" :loading="busy === 'all-backup'" @click="act('all-backup', () => dataApi.backupNow('all'), t('bo.data.backupQueued'))">
        {{ t('bo.data.backupAll') }}
      </BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="message" variant="success" class="mb-4">{{ message }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <div v-else class="space-y-2">
        <div v-for="row in rows" :key="row.database" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-mono text-sm break-all text-neutral-900">{{ row.database }}</p>
              <p class="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-neutral-500">
                <BaseBadge :variant="kindVariant[row.kind]">{{ t(`bo.data.dbKinds.${row.kind}`) }}</BaseBadge>
                <span v-if="row.school" class="truncate">{{ row.school.name }}</span>
                <span v-else-if="row.kind === 'template'">{{ t('bo.data.templateHint') }}</span>
                <span v-else-if="row.kind === 'shared'">{{ t('bo.data.schoolsInShared', { n: row.schools.length }) }}</span>
                <template v-if="row.extra">
                <BaseBadge :variant="extraStatusVariant[row.extra.status]">{{ t(`bo.data.extraStatus.${row.extra.status}`) }}</BaseBadge>
                <span>{{ t(`bo.data.newDb.from.${row.extra.source}`, { db: row.extra.source_database ?? '', file: row.extra.upload_name ?? '', id: row.extra.backup_id ?? '' }) }}</span>
              </template>
              </p>
              <p v-if="row.extra?.note" class="text-xs text-neutral-500">{{ row.extra.note }}</p>
              <p v-if="row.extra?.error" class="font-mono text-xs break-all text-red-700">{{ row.extra.error }}</p>
              <p v-if="row.server" class="font-mono text-xs break-all text-neutral-500">{{ row.server }}</p>
            </div>
            <span class="text-sm whitespace-nowrap text-neutral-600 tabular-nums">{{ formatBytes(row.size_bytes) }}</span>
          </div>
          <div class="mt-2 flex flex-wrap gap-1">
            <BaseBadge v-for="flag in row.flags" :key="flag" :variant="flagVariant[flag]">{{ t(`bo.data.flags.${flag}`) }}</BaseBadge>
            <BaseBadge v-if="!row.flags.length && (!row.extra || row.extra.status === 'ready')" variant="success">{{ t('bo.data.ok') }}</BaseBadge>
          </div>
          <p class="mt-2 text-xs text-neutral-500">
            {{ t('bo.data.lastBackup') }}: {{ row.last_backup_at ? formatDateTime(row.last_backup_at) : t('bo.data.never') }}
            <template v-if="row.pending_migrations"> · {{ t('bo.data.pending') }}: {{ row.pending_migrations }}</template>
          </p>
          <p v-if="row.error" class="mt-1 font-mono text-xs break-all text-red-700">{{ row.error }}</p>
          <ul v-if="row.schools.length" class="mt-2 space-y-1 border-t border-neutral-100 pt-2">
            <li v-for="s in row.schools" :key="s.id" class="flex items-center justify-between gap-2 text-sm">
              <RouterLink :to="`/schools/${s.id}`" class="truncate text-neutral-800 hover:underline">{{ s.name }}</RouterLink>
              <BaseButton v-if="canBackup" size="sm" variant="ghost" :loading="busy === `school-${s.id}`" @click="exportSchool(s.id)">{{ t('bo.data.exportSchool') }}</BaseButton>
            </li>
          </ul>
          <div v-if="hasActions(row)" class="mt-3 flex flex-wrap gap-2 border-t border-neutral-100 pt-3">
            <BaseButton v-if="canBackupRow(row)" size="sm" variant="outline" :loading="busy === row.database" @click="backup(row.database)">{{ t('bo.data.backupNow') }}</BaseButton>
            <BaseButton v-if="canMigrate && row.school && row.exists" size="sm" variant="ghost" @click="migrate(row)">{{ t('bo.data.migrate') }}</BaseButton>
            <BaseButton v-if="canMigrate && row.flags.includes('missing')" size="sm" @click="createDatabase(row)">{{ t('bo.data.createDatabase') }}</BaseButton>
            <BaseButton v-if="canCopyRow(row)" size="sm" variant="ghost" @click="openNew(row.database)">{{ t('bo.data.newDb.copy') }}</BaseButton>
            <BaseButton v-if="canDropRow(row)" size="sm" variant="ghost" class="text-danger-600" @click="openDrop(row)">{{ t('bo.data.dropDb.button') }}</BaseButton>
          </div>
        </div>
      </div>
    </div>

    <!-- Tablet / desktop: table -->
    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="rows" row-key="database" :loading="loading">
        <template #cell-database="{ row }">
          <div class="max-w-[16rem]">
            <p class="font-mono text-sm break-all text-neutral-900">{{ row.database }}</p>
            <p class="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-neutral-500">
              <BaseBadge :variant="kindVariant[row.kind as PlatformDatabase['kind']]">{{ t(`bo.data.dbKinds.${row.kind}`) }}</BaseBadge>
              <RouterLink v-if="row.school" :to="`/schools/${row.school.id}`" class="hover:underline">{{ row.school.name }}</RouterLink>
              <span v-else-if="row.kind === 'template'">{{ t('bo.data.templateHint') }}</span>
              <span v-else-if="row.kind === 'shared'">{{ t('bo.data.schoolsInShared', { n: row.schools.length }) }}</span>
              <template v-if="row.extra">
              <BaseBadge :variant="extraStatusVariant[row.extra.status]">{{ t(`bo.data.extraStatus.${row.extra.status}`) }}</BaseBadge>
              <span>{{ t(`bo.data.newDb.from.${row.extra.source}`, { db: row.extra.source_database ?? '', file: row.extra.upload_name ?? '', id: row.extra.backup_id ?? '' }) }}</span>
            </template>
            </p>
            <p v-if="row.extra?.note" class="text-xs text-neutral-500">{{ row.extra.note }}</p>
            <p v-if="row.extra?.error" class="font-mono text-xs break-all text-red-700">{{ row.extra.error }}</p>
            <p v-if="row.server" class="font-mono text-xs break-all text-neutral-500">{{ row.server }}</p>
            <ul v-if="row.schools.length" class="mt-2 space-y-0.5">
              <li v-for="s in row.schools" :key="s.id" class="flex items-center gap-2 text-xs">
                <RouterLink :to="`/schools/${s.id}`" class="text-neutral-700 hover:underline">{{ s.name }}</RouterLink>
                <BaseButton v-if="canBackup" size="sm" variant="ghost" :loading="busy === `school-${s.id}`" @click="exportSchool(s.id)">{{ t('bo.data.exportSchool') }}</BaseButton>
              </li>
            </ul>
            <p v-if="row.error" class="mt-1 font-mono text-xs break-all text-red-700">{{ row.error }}</p>
          </div>
        </template>
        <template #cell-size_bytes="{ row }"><span class="tabular-nums">{{ formatBytes(row.size_bytes) }}</span></template>
        <template #cell-last_backup_at="{ row }">{{ row.last_backup_at ? formatDateTime(row.last_backup_at) : t('bo.data.never') }}</template>
        <template #cell-flags="{ row }">
          <div class="flex flex-wrap gap-1">
            <BaseBadge v-for="flag in row.flags" :key="flag" :variant="flagVariant[flag as DatabaseFlag]">{{ t(`bo.data.flags.${flag}`) }}</BaseBadge>
            <BaseBadge v-if="!row.flags.length && (!row.extra || row.extra.status === 'ready')" variant="success">{{ t('bo.data.ok') }}</BaseBadge>
          </div>
          <p v-if="row.pending_migrations" class="mt-1 text-xs text-neutral-500">{{ t('bo.data.pending') }}: {{ row.pending_migrations }}</p>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex flex-col items-end gap-1">
            <BaseButton v-if="canBackupRow(row as PlatformDatabase)" size="sm" variant="ghost" :loading="busy === row.database" @click="backup(row.database)">{{ t('bo.data.backupNow') }}</BaseButton>
            <BaseButton v-if="canMigrate && row.school && row.exists" size="sm" variant="ghost" @click="migrate(row)">{{ t('bo.data.migrate') }}</BaseButton>
            <BaseButton v-if="canMigrate && row.flags.includes('missing')" size="sm" @click="createDatabase(row)">{{ t('bo.data.createDatabase') }}</BaseButton>
            <BaseButton v-if="canCopyRow(row as PlatformDatabase)" size="sm" variant="ghost" @click="openNew(row.database)">{{ t('bo.data.newDb.copy') }}</BaseButton>
            <BaseButton v-if="canDropRow(row as PlatformDatabase)" size="sm" variant="ghost" class="text-danger-600" @click="openDrop(row as PlatformDatabase)">{{ t('bo.data.dropDb.button') }}</BaseButton>
          </div>
        </template>
      </DataTable>
    </div>

    <NewDatabaseModal v-model="newOpen" :databases="rows" :copy-from="copyFrom" @created="onCreated" />
    <DropDatabaseModal v-model="dropOpen" :database="dropping" @dropped="onDropped" />
  </div>
</template>

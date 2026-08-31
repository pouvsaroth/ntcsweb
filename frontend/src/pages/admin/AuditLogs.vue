<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { adminUsersService } from '@/services/adminUsers'
import { auditLogsService, type AuditLogEntry } from '@/services/auditLogs'

const { t } = useI18n()

const ACTIONS = [
  'CREATE', 'UPDATE', 'DELETE', 'RESTORE',
  'LOGIN', 'LOGIN_FAILED', 'LOGIN_BLOCKED', 'LOGOUT',
  'PASSWORD_CHANGE', 'PASSWORD_RESET_REQUESTED', 'EMAIL_VERIFIED',
  'ROLE_CHANGE', 'STATUS_CHANGE', 'POSITION_CHANGE',
] as const

const MODULES = ['Auth', 'Users', 'Students', 'Staff', 'Positions', 'Roles', 'Programs', 'Enrollments'] as const

const actionBadgeVariant: Record<string, 'success' | 'warning' | 'danger' | 'neutral' | 'primary'> = {
  CREATE: 'success',
  UPDATE: 'warning',
  DELETE: 'danger',
  RESTORE: 'success',
  LOGIN: 'success',
  LOGIN_FAILED: 'danger',
  LOGIN_BLOCKED: 'danger',
  LOGOUT: 'neutral',
  PASSWORD_CHANGE: 'primary',
  PASSWORD_RESET_REQUESTED: 'primary',
  EMAIL_VERIFIED: 'primary',
  ROLE_CHANGE: 'warning',
  STATUS_CHANGE: 'warning',
  POSITION_CHANGE: 'warning',
}

const userOptions = ref<{ value: string; label: string }[]>([])
const dateFrom = ref('')
const dateTo = ref('')
const selectedUserId = ref('')
const selectedAction = ref('')
const selectedModule = ref('')

const { items, meta, loading, error, setPage, setSort, sort, setSearch, setFilter, fetch } =
  usePaginatedResource<AuditLogEntry>((query) => auditLogsService.list({ ...query, date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined }))

function onUserFilterChange(value: string) {
  selectedUserId.value = value
  setFilter('user_id', value || undefined)
}

function onActionFilterChange(value: string) {
  selectedAction.value = value
  setFilter('action', value || undefined)
}

function onModuleFilterChange(value: string) {
  selectedModule.value = value
  setFilter('module', value || undefined)
}

const columns = [
  { key: 'created_at', label: t('admin.auditLogs.columnDate'), sortable: true },
  { key: 'user', label: t('admin.auditLogs.columnUser') },
  { key: 'action', label: t('admin.auditLogs.columnAction') },
  { key: 'module', label: t('admin.auditLogs.columnModule') },
  { key: 'record', label: t('admin.auditLogs.columnRecord') },
  { key: 'description', label: t('admin.auditLogs.columnDescription') },
  { key: 'ip_address', label: t('admin.auditLogs.columnIp') },
  { key: 'actions', label: t('admin.auditLogs.columnActions'), align: 'text-right' },
]

const detailLog = ref<AuditLogEntry | null>(null)

const changedFields = computed(() => {
  if (!detailLog.value) return []

  const old = detailLog.value.old_values ?? {}
  const next = detailLog.value.new_values ?? {}
  const fields = new Set([...Object.keys(old), ...Object.keys(next)])

  return Array.from(fields).map((field) => ({
    field,
    old: old[field],
    new: next[field],
  }))
})

function formatDate(value: string): string {
  return new Date(value).toLocaleString()
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function resetFilters() {
  dateFrom.value = ''
  dateTo.value = ''
  selectedUserId.value = ''
  selectedAction.value = ''
  selectedModule.value = ''
  setFilter('user_id', undefined)
  setFilter('action', undefined)
  setFilter('module', undefined)
  setSearch('')
  void fetch()
}

onMounted(async () => {
  void fetch()

  const result = await adminUsersService.list({ page: 1, per_page: 100, sort: 'name' })
  userOptions.value = result.data.map((user) => ({ value: String(user.id), label: user.name }))
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.auditLogs.title') }}</h1>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 grid gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-6">
      <input
        type="search"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 lg:col-span-2"
        :placeholder="t('common.searchPlaceholder')"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect
        :model-value="selectedUserId"
        :options="userOptions"
        :placeholder="t('admin.auditLogs.filterAllUsers')"
        @update:model-value="onUserFilterChange"
      />
      <BaseSelect
        :model-value="selectedAction"
        :options="ACTIONS.map((a) => ({ value: a, label: a }))"
        :placeholder="t('admin.auditLogs.filterAllActions')"
        @update:model-value="onActionFilterChange"
      />
      <BaseSelect
        :model-value="selectedModule"
        :options="MODULES.map((m) => ({ value: m, label: m }))"
        :placeholder="t('admin.auditLogs.filterAllModules')"
        @update:model-value="onModuleFilterChange"
      />
      <div class="flex gap-2">
        <input v-model="dateFrom" type="date" class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200" />
        <input v-model="dateTo" type="date" class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200" />
      </div>
      <div class="flex justify-end gap-2 lg:col-span-6">
        <BaseButton variant="outline" size="sm" @click="resetFilters">{{ t('admin.auditLogs.reset') }}</BaseButton>
        <BaseButton size="sm" @click="fetch()">{{ t('admin.auditLogs.search') }}</BaseButton>
      </div>
    </div>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.auditLogs.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
      <template #cell-user="{ row }">{{ row.user?.name ?? t('admin.auditLogs.systemActor') }}</template>
      <template #cell-action="{ row }">
        <BaseBadge :variant="actionBadgeVariant[row.action] ?? 'neutral'">{{ row.action }}</BaseBadge>
      </template>
      <template #cell-module="{ row }">{{ row.module ?? '—' }}</template>
      <template #cell-record="{ row }">{{ row.record ?? '—' }}</template>
      <template #cell-description="{ row }">
        <span class="line-clamp-1">{{ row.description ?? '—' }}</span>
      </template>
      <template #cell-ip_address="{ row }">{{ row.ip_address ?? '—' }}</template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end">
          <button type="button" class="text-sm font-medium text-primary-700 hover:text-primary-800" @click="detailLog = row">
            {{ t('admin.auditLogs.view') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal :model-value="detailLog !== null" :title="t('admin.auditLogs.detailTitle')" size="lg" @update:model-value="detailLog = null">
      <div v-if="detailLog" class="space-y-6">
        <dl class="grid gap-4 sm:grid-cols-2">
          <div>
            <dt class="text-xs font-medium uppercase text-neutral-500">{{ t('admin.auditLogs.columnAction') }}</dt>
            <dd class="mt-1"><BaseBadge :variant="actionBadgeVariant[detailLog.action] ?? 'neutral'">{{ detailLog.action }}</BaseBadge></dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase text-neutral-500">{{ t('admin.auditLogs.columnUser') }}</dt>
            <dd class="mt-1 text-neutral-800">{{ detailLog.user?.name ?? t('admin.auditLogs.systemActor') }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase text-neutral-500">{{ t('admin.auditLogs.columnModule') }}</dt>
            <dd class="mt-1 text-neutral-800">{{ detailLog.module ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase text-neutral-500">{{ t('admin.auditLogs.columnRecord') }}</dt>
            <dd class="mt-1 text-neutral-800">{{ detailLog.record ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase text-neutral-500">{{ t('admin.auditLogs.columnDate') }}</dt>
            <dd class="mt-1 text-neutral-800">{{ formatDate(detailLog.created_at) }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase text-neutral-500">{{ t('admin.auditLogs.columnIp') }}</dt>
            <dd class="mt-1 text-neutral-800">{{ detailLog.ip_address ?? '—' }}</dd>
          </div>
        </dl>

        <p v-if="detailLog.description" class="rounded-lg bg-neutral-50 p-3 text-sm text-neutral-700">{{ detailLog.description }}</p>

        <div v-if="changedFields.length > 0">
          <h3 class="mb-2 text-sm font-semibold text-neutral-800">
            {{ detailLog.new_values && detailLog.old_values ? t('admin.auditLogs.changedFields') : detailLog.new_values ? t('admin.auditLogs.newValues') : t('admin.auditLogs.oldValues') }}
          </h3>
          <div class="overflow-x-auto rounded-lg border border-neutral-200">
            <table class="w-full text-left text-sm">
              <thead class="bg-neutral-50 text-neutral-600">
                <tr>
                  <th class="px-3 py-2 font-medium">{{ t('admin.auditLogs.field') }}</th>
                  <th v-if="detailLog.old_values" class="px-3 py-2 font-medium">{{ t('admin.auditLogs.oldValue') }}</th>
                  <th v-if="detailLog.new_values" class="px-3 py-2 font-medium">{{ t('admin.auditLogs.newValue') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in changedFields" :key="row.field">
                  <td class="px-3 py-2 font-medium text-neutral-700">{{ row.field }}</td>
                  <td v-if="detailLog.old_values" class="px-3 py-2 text-neutral-600">{{ formatValue(row.old) }}</td>
                  <td v-if="detailLog.new_values" class="px-3 py-2 text-neutral-600">{{ formatValue(row.new) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <template #footer>
        <BaseButton variant="outline" @click="detailLog = null">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

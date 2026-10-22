<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatDate, formatDateTime } from '@/utils/date'

import { demosApi, type DemoRole, type DemoRow } from '../../api'

/** Billing → Demos: who tried the demo on the website (docs/backoffice/stage-12-demo.md). */
const { t } = useI18n()

const { items, meta, loading, error, search, setSearch, setPage, setSort, setFilter, sort, fetch } = usePaginatedResource<DemoRow>((query) =>
  demosApi.list(query),
)

const ROLES: DemoRole[] = ['director', 'teacher', 'student']
const role = ref('')
const roleOptions = computed(() => [
  { value: '', label: t('bo.billing.demos.allRoles') },
  ...ROLES.map((r) => ({ value: r, label: t(`bo.billing.demos.roles.${r}`) })),
])
function filterRole(value: string): void {
  role.value = value
  setFilter('role', value || undefined)
}

const state = ref('')
const stateOptions = computed(() => [
  { value: '', label: t('bo.billing.demos.allStates') },
  { value: 'live', label: t('bo.billing.demos.states.live') },
  { value: 'ended', label: t('bo.billing.demos.states.ended') },
])
function filterState(value: string): void {
  state.value = value
  setFilter('state', value || undefined)
}

const roleVariant: Record<DemoRole, 'primary' | 'success' | 'warning'> = { director: 'primary', teacher: 'success', student: 'warning' }

const columns = computed(() => [
  { key: 'created_at', label: t('bo.billing.demos.when'), sortable: true },
  { key: 'visitor', label: t('bo.billing.demos.visitor') },
  { key: 'contact', label: t('bo.billing.demos.contact') },
  { key: 'role', label: t('bo.billing.demos.role') },
  { key: 'last_sign_in_at', label: t('bo.billing.demos.lastSignIn'), sortable: true },
  { key: 'state', label: t('bo.billing.demos.state') },
])

const stateLine = (row: DemoRow) =>
  row.live
    ? t('bo.billing.demos.until', { date: formatDate(row.expires_at) })
    : t('bo.billing.demos.endedOn', { date: formatDate(row.ended_at ?? row.expires_at) })

onMounted(() => fetch())
</script>

<template>
  <div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('bo.billing.demos.hint') }}</p>
    <div class="mb-4 grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <BaseInput :model-value="search" :placeholder="t('bo.billing.demos.search')" @update:model-value="setSearch" />
      <BaseSelect :model-value="role" :options="roleOptions" @update:model-value="filterRole" />
      <BaseSelect :model-value="state" :options="stateOptions" @update:model-value="filterState" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.billing.demos.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="min-w-0 truncate font-medium text-neutral-900">{{ row.name }}</p>
            <BaseBadge :variant="roleVariant[row.role]">{{ t(`bo.billing.demos.roles.${row.role}`) }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm break-all text-neutral-700">
            <template v-if="row.phone || row.email">{{ [row.phone, row.email].filter(Boolean).join(' · ') }}</template>
            <span v-else class="text-neutral-400">{{ t('bo.billing.demos.noContact') }}</span>
          </p>
          <div class="mt-2 flex items-center justify-between gap-2">
            <span class="text-xs text-neutral-500">{{ formatDateTime(row.created_at) }} · {{ t('bo.billing.demos.signIns') }} {{ row.sign_ins }}</span>
            <BaseBadge :variant="row.live ? 'success' : 'neutral'">{{ t(`bo.billing.demos.states.${row.live ? 'live' : 'ended'}`) }}</BaseBadge>
          </div>
          <p class="text-xs text-neutral-400">{{ stateLine(row) }}</p>
        </div>
      </div>
    </div>

    <!-- Tablet / desktop: table -->
    <div class="hidden sm:block">
      <DataTable
        :columns="columns"
        :rows="items"
        row-key="id"
        :loading="loading"
        :sort="sort"
        :empty-message="t('bo.billing.demos.empty')"
        @sort="(col) => setSort(sort === col ? `-${col}` : col)"
      >
        <template #cell-created_at="{ row }">
          <span class="text-sm whitespace-nowrap">{{ formatDateTime(row.created_at) }}</span>
        </template>
        <template #cell-visitor="{ row }">
          <span class="font-medium text-neutral-900">{{ row.name }}</span>
          <p v-if="row.ip_address" class="font-mono text-xs text-neutral-400">{{ row.ip_address }}</p>
        </template>
        <template #cell-contact="{ row }">
          <template v-if="row.phone || row.email">
            <p v-if="row.phone" class="text-sm">{{ row.phone }}</p>
            <p v-if="row.email" class="text-sm break-all text-neutral-600">{{ row.email }}</p>
          </template>
          <span v-else class="text-sm text-neutral-400">{{ t('bo.billing.demos.noContact') }}</span>
        </template>
        <template #cell-role="{ row }">
          <BaseBadge :variant="roleVariant[row.role]">{{ t(`bo.billing.demos.roles.${row.role}`) }}</BaseBadge>
        </template>
        <template #cell-last_sign_in_at="{ row }">
          <span class="text-sm whitespace-nowrap">{{ row.last_sign_in_at ? formatDateTime(row.last_sign_in_at) : t('bo.billing.demos.never') }}</span>
          <p class="text-xs text-neutral-500">{{ t('bo.billing.demos.signIns') }}: {{ row.sign_ins }}</p>
        </template>
        <template #cell-state="{ row }">
          <BaseBadge :variant="row.live ? 'success' : 'neutral'">{{ t(`bo.billing.demos.states.${row.live ? 'live' : 'ended'}`) }}</BaseBadge>
          <p class="mt-1 text-xs whitespace-nowrap text-neutral-500">{{ stateLine(row) }}</p>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />
  </div>
</template>

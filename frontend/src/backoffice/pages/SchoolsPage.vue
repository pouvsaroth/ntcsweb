<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatDate } from '@/utils/date'

import { schoolsApi, type BillingFlag, type HealthDot, type School } from '../api'
import { endLabel, flagVariant } from '../billing'
import SchoolCreateModal from '../components/SchoolCreateModal.vue'
import SchoolStatusBadge from '../components/SchoolStatusBadge.vue'
import { useSession } from '../session'

const { t } = useI18n()
const router = useRouter()
const session = useSession()

const { items, meta, loading, error, search, setSearch, setPage, setSort, setFilter, sort, fetch } = usePaginatedResource<School>((query) =>
  schoolsApi.list(query),
)

const status = ref('')
const statusOptions = computed(() => [
  { value: '', label: t('bo.schools.allStatuses') },
  ...(['provisioning', 'failed', 'active', 'suspended', 'archived'] as const).map((s) => ({ value: s, label: t(`bo.schools.statuses.${s}`) })),
])

function onStatus(value: string): void {
  status.value = value
  setFilter('status', value || undefined)
}

const columns = computed(() => [
  { key: 'name', label: t('bo.schools.name'), sortable: true },
  { key: 'slug', label: t('bo.schools.slug'), sortable: true },
  { key: 'status', label: t('bo.schools.status') },
  { key: 'plan', label: t('bo.billing.school.plan') },
  { key: 'owner', label: t('bo.schools.owner') },
  { key: 'created_at', label: t('bo.schools.created'), sortable: true },
])

// The health dot (stage 6a): green, amber, red; the title says which lines need a look.
const dotClass: Record<HealthDot['state'], string> = { ok: 'bg-green-500', warn: 'bg-amber-500', bad: 'bg-red-500' }
function dotTitle(health: HealthDot): string {
  const state = t(`bo.health.states.${health.state}`)
  return health.reasons.length ? `${state}: ${health.reasons.map((r) => t(`bo.health.lines.${r}`)).join(', ')}` : state
}

const createOpen = ref(false)
const open = (school: School) => router.push(`/schools/${school.id}`)

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('bo.schools.title') }}</h1>
      <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center">
        <div class="w-full sm:w-64">
          <BaseInput :model-value="search" :placeholder="t('bo.schools.search')" @update:model-value="setSearch" />
        </div>
        <div class="w-full sm:w-72">
          <BaseSelect :model-value="status" :options="statusOptions" @update:model-value="onStatus" />
        </div>
        <BaseButton v-if="session.can('platform.school.write')" @click="createOpen = true">{{ t('bo.schools.add') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.schools.empty') }}
      </p>
      <div v-else class="space-y-2">
        <button
          v-for="row in items"
          :key="row.id"
          type="button"
          class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]"
          @click="open(row)"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="flex items-center gap-2 font-medium text-neutral-900">
                <span v-if="row.health" class="h-2.5 w-2.5 shrink-0 rounded-full" :class="dotClass[row.health.state]" :title="dotTitle(row.health)" />
                <span class="truncate">{{ row.name }}</span>
              </p>
              <p v-if="row.name_en" class="truncate text-sm text-neutral-500">{{ row.name_en }}</p>
            </div>
            <SchoolStatusBadge :status="row.status" />
          </div>
          <p class="mt-2 font-mono text-xs text-neutral-500">{{ row.subdomain }}</p>
          <p v-if="row.owner" class="mt-1 truncate text-xs text-neutral-500">{{ row.owner.name }} · {{ row.owner.email }}</p>
          <p v-if="row.billing?.systems && row.billing.systems.length > 1" class="mt-1 flex flex-wrap gap-1">
            <BaseBadge v-for="s in row.billing.systems" :key="s.id" variant="primary">{{ s.code }}</BaseBadge>
          </p>
          <p v-if="row.billing?.subscription" class="mt-1 flex flex-wrap items-center gap-1 text-xs text-neutral-600">
            <span>{{ row.billing.subscription.plan?.name }}</span>
            <BaseBadge v-if="row.billing.flag !== 'none'" :variant="flagVariant[row.billing.flag]">{{ t(`bo.billing.flags.${row.billing.flag}`) }}</BaseBadge>
            <span v-if="row.billing.subscription.live" class="text-neutral-500">· {{ endLabel(row.billing.subscription, t) }}</span>
          </p>
        </button>
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
        :empty-message="t('bo.schools.empty')"
        @sort="(col) => setSort(sort === col ? `-${col}` : col)"
      >
        <template #cell-name="{ row }">
          <span class="flex items-center gap-2">
            <span v-if="row.health" class="h-2.5 w-2.5 shrink-0 rounded-full" :class="dotClass[row.health.state as HealthDot['state']]" :title="dotTitle(row.health as HealthDot)" />
            <RouterLink :to="`/schools/${row.id}`" class="font-medium text-secondary-700 hover:underline">{{ row.name }}</RouterLink>
          </span>
          <p v-if="row.name_en" class="text-xs text-neutral-500">{{ row.name_en }}</p>
        </template>
        <template #cell-slug="{ row }"><span class="font-mono text-xs">{{ row.slug }}</span></template>
        <template #cell-status="{ row }"><SchoolStatusBadge :status="row.status" /></template>
        <template #cell-plan="{ row }">
          <template v-if="row.billing?.subscription">
            <p class="text-sm">{{ row.billing.subscription.plan?.name ?? '—' }}</p>
            <p v-if="row.billing.systems && row.billing.systems.length > 1" class="flex flex-wrap gap-1 py-0.5">
              <BaseBadge v-for="s in row.billing.systems" :key="s.id" variant="primary">{{ s.code }}</BaseBadge>
            </p>
            <p class="text-xs text-neutral-500">
              <BaseBadge v-if="row.billing.flag !== 'none'" :variant="flagVariant[row.billing.flag as BillingFlag]" class="mr-1">
                {{ t(`bo.billing.flags.${row.billing.flag}`) }}
              </BaseBadge>
              <template v-if="row.billing.subscription.live">{{ endLabel(row.billing.subscription, t) }}</template>
            </p>
          </template>
          <span v-else class="text-neutral-400">—</span>
        </template>
        <template #cell-owner="{ row }">
          <template v-if="row.owner">
            <p class="text-sm">{{ row.owner.name }}</p>
            <p class="text-xs text-neutral-500">{{ row.owner.email }}</p>
          </template>
          <span v-else class="text-neutral-400">—</span>
        </template>
        <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />

    <SchoolCreateModal v-model="createOpen" @created="open" />
  </div>
</template>

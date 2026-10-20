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
import { formatMoney } from '@/utils/currency'
import { formatDateTime } from '@/utils/date'

import { ApiRequestError } from '@/types/api'

import { signupsApi, type SignupRow, type SignupStatus } from '../../api'
import { useSession } from '../../session'

/** Billing → Sign-ups: schools that signed up themselves on the website (docs/backoffice/stage-8-signup.md). */
const { t } = useI18n()

const { items, meta, loading, error, search, setSearch, setPage, setSort, setFilter, sort, fetch } = usePaginatedResource<SignupRow>((query) =>
  signupsApi.list(query),
)

const STATUSES: SignupStatus[] = ['pending_payment', 'paid', 'created', 'expired', 'failed']
const status = ref('')
const statusOptions = computed(() => [
  { value: '', label: t('bo.billing.signups.allStatuses') },
  ...STATUSES.map((s) => ({ value: s, label: t(`bo.billing.signups.statuses.${s}`) })),
])
function filterStatus(value: string): void {
  status.value = value
  setFilter('status', value || undefined)
}

const variant: Record<SignupStatus, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  pending_payment: 'warning',
  paid: 'primary',
  created: 'success',
  expired: 'neutral',
  failed: 'danger',
}

const columns = computed(() => [
  { key: 'created_at', label: t('bo.billing.signups.when'), sortable: true },
  { key: 'school', label: t('bo.billing.signups.school') },
  { key: 'owner', label: t('bo.billing.signups.owner') },
  { key: 'plan', label: t('bo.billing.signups.plan') },
  { key: 'amount', label: t('bo.billing.signups.amount'), sortable: true },
  { key: 'status', label: t('bo.billing.signups.status') },
])

const planLabel = (s: SignupRow) =>
  s.kind === 'trial' ? t('bo.billing.signups.trial') : `${s.plan?.name ?? '—'}${s.periods && s.periods > 1 ? ` ${t('bo.billing.signups.periods', { n: s.periods })}` : ''}`
const session = useSession()
const canManage = computed(() => session.can('platform.billing.manage'))
const retrying = ref<number | null>(null)
const retryError = ref<string | null>(null)
async function retry(row: SignupRow): Promise<void> {
  retrying.value = row.id
  retryError.value = null
  try {
    const r = await signupsApi.retry(row.id)
    if (r.failure) retryError.value = r.failure
    await fetch()
  } catch (e) {
    retryError.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    retrying.value = null
  }
}

const money = (s: SignupRow) => (s.kind === 'trial' ? '—' : formatMoney(Number(s.amount), s.currency))

onMounted(() => fetch())
</script>

<template>
  <div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('bo.billing.signups.hint') }}</p>
    <div class="mb-4 grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <BaseInput :model-value="search" :placeholder="t('bo.billing.signups.search')" @update:model-value="setSearch" />
      <BaseSelect :model-value="status" :options="statusOptions" @update:model-value="filterStatus" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="retryError" variant="danger" class="mb-4">{{ retryError }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.billing.signups.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.school_name }}</p>
              <p class="font-mono text-xs text-neutral-500">{{ row.slug }}</p>
            </div>
            <BaseBadge :variant="variant[row.status]">{{ t(`bo.billing.signups.statuses.${row.status}`) }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm text-neutral-700">{{ planLabel(row) }} · {{ money(row) }}</p>
          <p class="mt-1 text-xs break-all text-neutral-500">{{ row.owner_name }} · {{ row.owner_email }} · {{ row.owner_phone }}</p>
          <p class="text-xs text-neutral-400">{{ formatDateTime(row.created_at) }}</p>
          <p v-if="row.failure" class="mt-1 text-xs text-red-600">{{ row.failure }}</p>
          <RouterLink v-if="row.school" :to="`/schools/${row.school.id}`" class="mt-2 inline-block text-sm text-secondary-700 hover:underline">
            {{ t('bo.billing.signups.openSchool') }}
          </RouterLink>
          <button
            v-if="canManage && row.can_retry"
            type="button"
            class="mt-2 text-sm text-secondary-700 hover:underline disabled:opacity-50"
            :disabled="retrying === row.id"
            @click="retry(row)"
          >
            {{ t('bo.billing.signups.retry') }}
          </button>
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
        :empty-message="t('bo.billing.signups.empty')"
        @sort="(col) => setSort(sort === col ? `-${col}` : col)"
      >
        <template #cell-created_at="{ row }">
          <span class="text-sm whitespace-nowrap">{{ formatDateTime(row.created_at) }}</span>
        </template>
        <template #cell-school="{ row }">
          <RouterLink v-if="row.school" :to="`/schools/${row.school.id}`" class="text-secondary-700 hover:underline">{{ row.school_name }}</RouterLink>
          <span v-else>{{ row.school_name }}</span>
          <p class="font-mono text-xs text-neutral-500">{{ row.slug }}</p>
        </template>
        <template #cell-owner="{ row }">
          {{ row.owner_name }}
          <p class="text-xs text-neutral-500">{{ row.owner_email }} · {{ row.owner_phone }}</p>
        </template>
        <template #cell-plan="{ row }">{{ planLabel(row) }}</template>
        <template #cell-amount="{ row }">
          <span class="font-medium whitespace-nowrap">{{ money(row) }}</span>
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="variant[row.status]">{{ t(`bo.billing.signups.statuses.${row.status}`) }}</BaseBadge>
          <p v-if="row.failure" class="mt-1 text-xs text-red-600">{{ row.failure }}</p>
          <p v-if="row.bakong_hash" class="mt-1 font-mono text-[11px] break-all text-neutral-400">{{ row.bakong_hash }}</p>
          <button
            v-if="canManage && row.can_retry"
            type="button"
            class="mt-1 text-sm text-secondary-700 hover:underline disabled:opacity-50"
            :disabled="retrying === row.id"
            @click="retry(row)"
          >
            {{ t('bo.billing.signups.retry') }}
          </button>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />
  </div>
</template>

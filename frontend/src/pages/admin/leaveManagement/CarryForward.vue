<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatDays, leaveBalancesService, type CarryForwardResult, type LeaveBalanceEntry } from '@/services/leaveManagement'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Leave Management > Carry forward — the unused days that moved into a
 * year from the one before, and (for a manager) running it: each working
 * staff member's unused days of each leave type, capped by their policy and
 * expiring after its months. Running it again replaces the earlier result.
 * Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canManage = computed(() => auth.can('leave-management.manage'))

const thisYear = new Date().getFullYear()
/** The year carried INTO. */
const year = ref(thisYear)
const yearOptions = [thisYear - 1, thisYear, thisYear + 1].map((y) => ({ value: String(y), label: String(y) }))

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<LeaveBalanceEntry>((query) => leaveBalancesService.carried({ ...query, per_page: 100 }, year.value))

watch(year, () => {
  result.value = null
  void fetch()
})

const totalDays = computed(() => items.value.reduce((sum, entry) => sum + entry.days, 0))

const columns = computed(() => [
  { key: 'staff', label: t('admin.leaveManagement.requests.staff') },
  { key: 'type', label: t('admin.leaveManagement.policies.leaveType') },
  { key: 'days', label: t('admin.leaveManagement.requests.days'), align: 'text-right' },
  { key: 'expires', label: t('admin.leaveManagement.carry.expires') },
])

const running = ref(false)
const runError = ref<string | null>(null)
const result = ref<CarryForwardResult | null>(null)

async function run() {
  const from = year.value - 1
  if (!(await confirmDialog.confirm({ message: t('admin.leaveManagement.carry.confirm', { from, to: year.value }) }))) return
  running.value = true
  runError.value = null
  try {
    result.value = await leaveBalancesService.carryForward(from)
    await fetch()
  } catch (e) {
    runError.value = e instanceof ApiRequestError ? e.message : t('admin.leaveManagement.saveFailed')
  } finally {
    running.value = false
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-28" :model-value="String(year)" :options="yearOptions" @update:model-value="year = Number($event)" />
      <p class="text-sm text-neutral-500">{{ t('admin.leaveManagement.carry.total', { days: formatDays(totalDays), from: year - 1 }) }}</p>
      <BaseButton v-if="canManage" class="ml-auto" :loading="running" @click="run">
        {{ t('admin.leaveManagement.carry.run', { from: year - 1, to: year }) }}
      </BaseButton>
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.leaveManagement.carry.hint') }}</p>

    <BaseAlert v-if="error || runError" variant="danger" class="mb-4">{{ error || runError }}</BaseAlert>
    <BaseAlert v-if="result" variant="success" class="mb-4">
      {{ t('admin.leaveManagement.carry.done', { count: result.entries, days: formatDays(result.days) }) }}
      <template v-if="result.pending_requests > 0"> {{ t('admin.leaveManagement.carry.pendingWarning', { count: result.pending_requests }) }}</template>
    </BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.leaveManagement.carry.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-800">{{ row.staff?.name ?? '—' }}</p>
              <p class="flex items-center gap-1.5 text-xs text-neutral-500">
                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: row.leave_type?.color ?? '#9ca3af' }" />{{ row.leave_type?.name }}
              </p>
            </div>
            <p class="shrink-0 text-lg font-semibold tabular-nums text-neutral-900">{{ formatDays(row.days) }}</p>
          </div>
          <p class="mt-1 text-xs text-neutral-500">
            {{ row.expires_on ? t('admin.leaveManagement.carry.expiresOn', { date: formatDate(row.expires_on) }) : t('admin.leaveManagement.carry.noExpiry') }}
          </p>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.leaveManagement.carry.empty')">
        <template #cell-staff="{ row }">
          <p class="font-medium text-neutral-800">{{ row.staff?.name ?? '—' }}</p>
          <p class="text-xs text-neutral-500">{{ row.staff?.employee_code }}</p>
        </template>
        <template #cell-type="{ row }">
          <p class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: row.leave_type?.color ?? '#9ca3af' }" />{{ row.leave_type?.name }}
          </p>
        </template>
        <template #cell-days="{ row }"><span class="font-semibold tabular-nums">{{ formatDays(row.days) }}</span></template>
        <template #cell-expires="{ row }">{{ row.expires_on ? formatDate(row.expires_on) : t('admin.leaveManagement.carry.noExpiry') }}</template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
  </div>
</template>

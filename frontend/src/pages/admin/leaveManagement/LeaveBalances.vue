<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  formatDays,
  leaveBalancesService,
  leaveTypesService,
  type LeaveBalanceEntry,
  type LeaveType,
  type StaffBalanceDetail,
  type StaffBalanceRow,
  type StaffLeaveBalance,
} from '@/services/leaveManagement'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Leave Management > Leave balance — each working staff member's
 * days left of every leave type a policy covers, for a year. Opening one
 * shows how it adds up (entitlement + carried forward + adjustments − used −
 * pending) and HR's adjustments, where a manager can add or undo one. Cards
 * on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canManage = computed(() => auth.can('leave-management.manage'))

const thisYear = new Date().getFullYear()
const year = ref(thisYear)
const yearOptions = [thisYear - 2, thisYear - 1, thisYear, thisYear + 1].map((y) => ({ value: String(y), label: String(y) }))
const typeFilter = ref('')
const leaveTypes = ref<LeaveType[]>([])
const typeFilterOptions = computed(() => [
  { value: '', label: t('admin.leaveManagement.policies.allTypes') },
  ...leaveTypes.value.filter((type) => type.is_active).map((type) => ({ value: String(type.id), label: type.name })),
])

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<StaffBalanceRow>((query) =>
  leaveBalancesService.list(query, year.value, typeFilter.value ? Number(typeFilter.value) : null),
)

watch([year, typeFilter], () => void fetch())

/** The leave types shown as columns — those any row on this page has a balance of. */
const columnTypes = computed(() => {
  const seen = new Map<number, StaffLeaveBalance['leave_type']>()
  for (const row of items.value) for (const balance of row.balances) seen.set(balance.leave_type.id, balance.leave_type)
  return [...seen.values()]
})

function balanceOf(row: StaffBalanceRow, typeId: number): StaffLeaveBalance | undefined {
  return row.balances.find((balance) => balance.leave_type.id === typeId)
}

// --- One staff member ---------------------------------------------------------------

const detail = ref<StaffBalanceDetail | null>(null)
const detailOpen = ref(false)
const detailLoading = ref(false)
const detailError = ref<string | null>(null)

async function openDetail(row: StaffBalanceRow) {
  detailOpen.value = true
  detail.value = null
  adjustOpen.value = false
  await loadDetail(row.staff.id)
}

async function loadDetail(staffId: number) {
  detailLoading.value = true
  detailError.value = null
  try {
    detail.value = await leaveBalancesService.get(staffId, year.value)
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.leaveManagement.saveFailed')
  } finally {
    detailLoading.value = false
  }
}

const adjustOpen = ref(false)
const adjust = reactive({ leave_type_id: '', days: '', note: '' })
const adjustErrors = ref<Record<string, string[]>>({})
const adjusting = ref(false)

function openAdjust() {
  Object.assign(adjust, { leave_type_id: detail.value?.balances[0] ? String(detail.value.balances[0].leave_type.id) : '', days: '', note: '' })
  adjustErrors.value = {}
  adjustOpen.value = true
}

async function saveAdjust() {
  if (!detail.value) return
  adjusting.value = true
  adjustErrors.value = {}
  detailError.value = null
  try {
    await leaveBalancesService.adjust({
      staff_id: detail.value.staff.id,
      leave_type_id: Number(adjust.leave_type_id),
      year: year.value,
      days: Number(adjust.days),
      note: adjust.note,
    })
    adjustOpen.value = false
    await Promise.all([loadDetail(detail.value.staff.id), fetch()])
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) adjustErrors.value = e.errors
    else detailError.value = e instanceof ApiRequestError ? e.message : t('admin.leaveManagement.saveFailed')
  } finally {
    adjusting.value = false
  }
}

async function removeEntry(entry: LeaveBalanceEntry) {
  if (!detail.value) return
  if (!(await confirmDialog.confirm({ message: t('admin.leaveManagement.balances.undoConfirm'), danger: true }))) return
  try {
    await leaveBalancesService.removeEntry(entry.id)
    await Promise.all([loadDetail(detail.value.staff.id), fetch()])
  } catch (e) {
    detailError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

const signed = (days: number) => (days > 0 ? `+${formatDays(days)}` : formatDays(days))

onMounted(async () => {
  void fetch()
  leaveTypes.value = await leaveTypesService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-28" :model-value="String(year)" :options="yearOptions" @update:model-value="year = Number($event)" />
      <BaseSelect class="w-full sm:w-48" :model-value="typeFilter" :options="typeFilterOptions" @update:model-value="typeFilter = $event" />
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.leaveManagement.balances.hint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('admin.leaveManagement.balances.empty') }}
    </p>
    <template v-else>
      <!-- Cards on a phone (below sm) -->
      <div class="space-y-2 sm:hidden">
        <button
          v-for="row in items"
          :key="row.staff.id"
          type="button"
          class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]"
          @click="openDetail(row)"
        >
          <p class="text-sm font-semibold text-neutral-800">{{ row.staff.name }} <span class="font-normal text-neutral-500">({{ row.staff.employee_code }})</span></p>
          <p v-if="row.balances.length === 0" class="mt-1 text-xs text-neutral-500">{{ t('admin.leaveManagement.balances.noPolicy') }}</p>
          <div v-else class="mt-2 flex flex-wrap gap-2">
            <span v-for="balance in row.balances" :key="balance.leave_type.id" class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-2.5 py-1 text-xs text-neutral-700">
              <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: balance.leave_type.color ?? '#9ca3af' }" />
              {{ balance.leave_type.name }}:
              <span class="font-semibold tabular-nums" :class="balance.available < 0 ? 'text-danger-600' : ''">{{ formatDays(balance.available) }}</span>/{{ formatDays(balance.total) }}
            </span>
          </div>
        </button>
      </div>

      <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
        <table class="w-full min-w-max text-left text-sm">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-600">
            <tr>
              <th class="px-4 py-3 font-medium">{{ t('admin.leaveManagement.requests.staff') }}</th>
              <th v-for="type in columnTypes" :key="type.id" class="px-4 py-3 text-right font-medium">
                <span class="inline-flex items-center gap-1.5">
                  <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: type.color ?? '#9ca3af' }" />{{ type.name }}
                </span>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in items" :key="row.staff.id">
              <td class="px-4 py-3">
                <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="openDetail(row)">{{ row.staff.name }}</button>
                <p class="text-xs text-neutral-500">{{ row.staff.employee_code }}</p>
              </td>
              <td v-for="type in columnTypes" :key="type.id" class="px-4 py-3 text-right tabular-nums">
                <template v-if="balanceOf(row, type.id)">
                  <span class="font-semibold" :class="balanceOf(row, type.id)!.available < 0 ? 'text-danger-600' : 'text-neutral-900'">{{ formatDays(balanceOf(row, type.id)!.available) }}</span>
                  <span class="text-neutral-400"> / {{ formatDays(balanceOf(row, type.id)!.total) }}</span>
                  <p v-if="balanceOf(row, type.id)!.pending > 0" class="text-xs text-amber-700">{{ t('admin.leaveManagement.balances.pendingN', { days: formatDays(balanceOf(row, type.id)!.pending) }) }}</p>
                </template>
                <span v-else class="text-neutral-300">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal v-model="detailOpen" size="lg" :title="detail ? `${detail.staff.name} — ${year}` : t('admin.leaveManagement.tabs.balances')">
      <BaseAlert v-if="detailError" variant="danger" class="mb-4">{{ detailError }}</BaseAlert>
      <div v-if="detailLoading && !detail" class="flex justify-center py-8"><BaseSpinner /></div>

      <template v-else-if="detail">
        <p v-if="detail.balances.length === 0" class="py-4 text-center text-sm text-neutral-500">{{ t('admin.leaveManagement.balances.noPolicy') }}</p>
        <div class="space-y-3">
          <div v-for="balance in detail.balances" :key="balance.leave_type.id" class="rounded-lg border border-neutral-200 p-3">
            <div class="flex items-center justify-between gap-3">
              <p class="flex items-center gap-2 text-sm font-semibold text-neutral-800">
                <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: balance.leave_type.color ?? '#9ca3af' }" />{{ balance.leave_type.name }}
              </p>
              <p class="text-sm">
                <span class="text-lg font-semibold tabular-nums" :class="balance.available < 0 ? 'text-danger-600' : 'text-neutral-900'">{{ formatDays(balance.available) }}</span>
                <span class="text-neutral-500"> {{ t('admin.leaveManagement.balances.left') }}</span>
              </p>
            </div>
            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
              <div class="flex justify-between gap-2"><dt class="text-neutral-500">{{ t('admin.leaveManagement.balances.entitlement') }}</dt><dd class="tabular-nums">{{ formatDays(balance.entitlement) }}</dd></div>
              <div class="flex justify-between gap-2">
                <dt class="text-neutral-500">{{ t('admin.leaveManagement.balances.carried') }}</dt>
                <dd class="tabular-nums">{{ formatDays(balance.carried_forward - balance.carried_lapsed) }}</dd>
              </div>
              <div class="flex justify-between gap-2"><dt class="text-neutral-500">{{ t('admin.leaveManagement.balances.adjustments') }}</dt><dd class="tabular-nums">{{ signed(balance.adjustments) }}</dd></div>
              <div class="flex justify-between gap-2"><dt class="text-neutral-500">{{ t('admin.leaveManagement.balances.used') }}</dt><dd class="tabular-nums">−{{ formatDays(balance.used) }}</dd></div>
              <div class="flex justify-between gap-2"><dt class="text-neutral-500">{{ t('admin.leaveManagement.balances.pending') }}</dt><dd class="tabular-nums">−{{ formatDays(balance.pending) }}</dd></div>
            </dl>
            <p v-if="balance.carried_forward > 0 && balance.carried_expires_on" class="mt-2 text-xs text-neutral-500">
              {{ t('admin.leaveManagement.balances.carriedExpires', { days: formatDays(balance.carried_forward), date: formatDate(balance.carried_expires_on) }) }}
              <template v-if="balance.carried_lapsed > 0"> · {{ t('admin.leaveManagement.balances.lapsed', { days: formatDays(balance.carried_lapsed) }) }}</template>
            </p>
          </div>
        </div>

        <div class="mt-5 flex items-center justify-between gap-3">
          <h3 class="text-sm font-semibold text-neutral-800">{{ t('admin.leaveManagement.balances.history') }}</h3>
          <BaseButton v-if="canManage && detail.balances.length > 0 && !adjustOpen" size="sm" variant="outline" @click="openAdjust">{{ t('admin.leaveManagement.balances.adjust') }}</BaseButton>
        </div>

        <form v-if="adjustOpen" class="mt-3 space-y-3 rounded-lg bg-neutral-50 p-3" @submit.prevent="saveAdjust">
          <div class="grid gap-3 sm:grid-cols-2">
            <BaseSelect
              v-model="adjust.leave_type_id"
              :options="detail.balances.map((b) => ({ value: String(b.leave_type.id), label: b.leave_type.name }))"
              :label="t('admin.leaveManagement.policies.leaveType')"
              :error="adjustErrors.leave_type_id?.[0]"
            />
            <BaseInput
              v-model="adjust.days"
              type="number"
              step="0.5"
              required
              :label="t('admin.leaveManagement.balances.days')"
              :hint="t('admin.leaveManagement.balances.daysHint')"
              :error="adjustErrors.days?.[0]"
            />
          </div>
          <BaseInput v-model="adjust.note" required :label="t('admin.leaveManagement.balances.note')" :error="adjustErrors.note?.[0]" />
          <div class="flex justify-end gap-2">
            <BaseButton size="sm" variant="outline" @click="adjustOpen = false">{{ t('common.close') }}</BaseButton>
            <BaseButton size="sm" type="submit" :loading="adjusting">{{ t('common.save') }}</BaseButton>
          </div>
        </form>

        <p v-if="detail.entries.length === 0" class="mt-2 text-sm text-neutral-500">{{ t('admin.leaveManagement.balances.noHistory') }}</p>
        <ul v-else class="mt-2 divide-y divide-neutral-100 text-sm">
          <li v-for="entry in detail.entries" :key="entry.id" class="flex items-start justify-between gap-3 py-2">
            <div class="min-w-0">
              <p class="font-medium text-neutral-800">
                {{ entry.leave_type?.name }} · <span class="tabular-nums">{{ signed(entry.days) }}</span>
                <span class="font-normal text-neutral-500">— {{ entry.kind === 'carry_forward' ? t('admin.leaveManagement.balances.kindCarry') : t('admin.leaveManagement.balances.kindAdjustment') }}</span>
              </p>
              <p class="text-xs text-neutral-500">{{ entry.note }}<template v-if="entry.created_by"> · {{ entry.created_by }}</template> · {{ formatDate(entry.created_at) }}</p>
            </div>
            <button v-if="canManage && entry.kind === 'adjustment'" type="button" class="shrink-0 text-sm font-medium text-danger-600" @click="removeEntry(entry)">
              {{ t('admin.leaveManagement.balances.undo') }}
            </button>
          </li>
        </ul>
      </template>
      <template #footer>
        <BaseButton variant="outline" @click="detailOpen = false">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

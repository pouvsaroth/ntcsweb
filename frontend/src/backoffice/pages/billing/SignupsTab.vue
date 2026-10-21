<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatMoney } from '@/utils/currency'
import { formatDateTime } from '@/utils/date'

import { ApiRequestError } from '@/types/api'

import { signupsApi, type PaymentMethod, type SignupRow, type SignupStatus } from '../../api'
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
  (s.system && s.system.code !== 'school' ? `${s.system.name} · ` : '') +
  (s.kind === 'trial' ? t('bo.billing.signups.trial') : `${s.plan?.name ?? '—'}${s.periods && s.periods > 1 ? ` ${t('bo.billing.signups.periods', { n: s.periods })}` : ''}`)
const session = useSession()
const canManage = computed(() => session.can('platform.billing.manage'))
const retrying = ref<number | null>(null)
const retryError = ref<string | null>(null)
// "Payment received": staff saw the money arrive (e.g. paid inside one bank, which Bakong never sees).
const confirming = ref<SignupRow | null>(null)
const confirmForm = ref({ amount: '', method: 'khqr' as PaymentMethod, reference: '' })
const confirmBusy = ref(false)
const confirmError = ref<ApiRequestError | null>(null)
const confirmed = ref<string | null>(null)
const methodOptions = computed(() =>
  (['khqr', 'bank', 'cash', 'other'] as PaymentMethod[]).map((m) => ({ value: m, label: t(`bo.billing.payments.methods.${m}`) })),
)
function openConfirm(row: SignupRow): void {
  confirming.value = row
  confirmForm.value = { amount: String(Number(row.amount)), method: 'khqr', reference: '' }
  confirmError.value = null
}
async function confirmPayment(): Promise<void> {
  if (!confirming.value) return
  confirmBusy.value = true
  confirmError.value = null
  try {
    const r = await signupsApi.confirmPayment(confirming.value.id, {
      amount: confirmForm.value.amount || null,
      method: confirmForm.value.method,
      reference: confirmForm.value.reference.trim() || null,
    })
    if (r.failure) {
      confirmError.value = new ApiRequestError(r.failure, 0)
    } else {
      confirmed.value = r.owner_needs_invite ? t('bo.billing.signups.confirmedInvite') : t('bo.billing.signups.confirmed')
      confirming.value = null
    }
    await fetch()
  } catch (e) {
    confirmError.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    confirmBusy.value = false
  }
}

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
    <BaseAlert v-if="confirmed" variant="success" class="mb-4">{{ confirmed }}</BaseAlert>

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
          <button v-if="canManage && row.can_confirm" type="button" class="mt-2 mr-3 text-sm font-medium text-green-700 hover:underline" @click="openConfirm(row)">
            {{ t('bo.billing.signups.confirm') }}
          </button>
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
          <button v-if="canManage && row.can_confirm" type="button" class="mt-1 block text-sm font-medium text-green-700 hover:underline" @click="openConfirm(row)">
            {{ t('bo.billing.signups.confirm') }}
          </button>
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

    <BaseModal :model-value="confirming !== null" :title="t('bo.billing.signups.confirmTitle')" size="sm" @update:model-value="confirming = null">
      <form v-if="confirming" id="bo-confirm-signup" class="space-y-4" @submit.prevent="confirmPayment">
        <p class="text-sm text-neutral-600">{{ t('bo.billing.signups.confirmHint', { school: confirming.school_name, plan: planLabel(confirming) }) }}</p>
        <BaseAlert v-if="confirmError && !confirmError.errors" variant="danger">{{ confirmError.message }}</BaseAlert>
        <BaseInput
          v-model="confirmForm.amount"
          type="number"
          :label="`${t('bo.billing.signups.amount')} (${confirming.currency})`"
          :error="confirmError?.fieldError('amount')"
        />
        <BaseSelect v-model="confirmForm.method" :options="methodOptions" :label="t('bo.billing.payments.method')" />
        <BaseInput
          v-model="confirmForm.reference"
          :label="t('bo.billing.payments.reference')"
          :hint="t('bo.billing.payments.referenceHint')"
          :error="confirmError?.fieldError('reference')"
        />
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="confirming = null">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="bo-confirm-signup" :loading="confirmBusy">{{ t('bo.billing.signups.confirmButton') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

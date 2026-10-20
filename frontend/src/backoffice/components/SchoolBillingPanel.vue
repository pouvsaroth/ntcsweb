<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

import { billingApi, paymentsApi, type Plan, type School, type SchoolBilling, type SchoolPaymentRow } from '../api'
import { endLabel, flagVariant, priceLabel, studentsLabel } from '../billing'
import ReasonModal from './ReasonModal.vue'
import RecordPaymentModal from './RecordPaymentModal.vue'

/**
 * A customer's plans: one system at a time (stage 9) — what it is on, until
 * when, its history; subscribe, change, edit the end, cancel (stage 5a), and
 * add another system.
 */
const props = defineProps<{ school: School; canManage: boolean }>()
const emit = defineEmits<{ changed: [] }>()

const { t, locale } = useI18n()

const billing = ref<SchoolBilling | null>(null)
const plans = ref<Plan[]>([])
/** The system shown; null = the customer's main one (school first). */
const productId = ref<number | null>(null)
const systemName = (s: { name: string; name_km: string | null }) => (locale.value === 'km' && s.name_km ? s.name_km : s.name)
const error = ref<string | null>(null)
const live = computed(() => (billing.value?.subscription?.live ? billing.value.subscription : null))

/** Active students as a share of the plan's limit; null with no limit. */
const usageShare = computed(() => {
  const usage = billing.value?.usage
  return usage && usage.limit ? usage.active / usage.limit : null
})
const usageBar = computed(() => {
  const share = usageShare.value ?? 0
  return share >= 1 ? 'bg-red-500' : share >= 0.9 ? 'bg-amber-500' : 'bg-green-500'
})

const payments = ref<SchoolPaymentRow[]>([])
const paymentCount = ref(0)
const payOpen = ref(false)
const payable = computed(() => billing.value?.subscription && ['trial', 'active', 'expired'].includes(billing.value.subscription.status))

async function load(): Promise<void> {
  try {
    const [b, p] = await Promise.all([
      billingApi.school(props.school.id, productId.value),
      paymentsApi.list({ page: 1, per_page: 5, filter: { tenant_id: String(props.school.id) } }),
    ])
    billing.value = b
    productId.value = b.product_id
    payments.value = p.data
    paymentCount.value = p.pagination?.type === 'length_aware' ? p.pagination.total : p.data.length
    error.value = null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

async function loadPlans(): Promise<void> {
  if (plans.value.length === 0) plans.value = (await billingApi.plans()).plans
}

function showSystem(id: number): void {
  productId.value = id
  void load()
}

/** "Add system": its plans are every active plan of a system the customer has no live subscription to. */
const adding = ref(false)
const heldLive = computed(() => (billing.value?.systems ?? []).filter((s) => s.subscription?.live).map((s) => s.product.id))

const planOptions = computed(() => [
  { value: '', label: t('bo.billing.school.choosePlan') },
  ...plans.value
    .filter((p) => p.status === 'active' && p.id !== live.value?.plan?.id)
    // Adding: another system. Otherwise: a plan of the system shown.
    .filter((p) => (adding.value ? !heldLive.value.includes(p.product?.id ?? 0) : productId.value === null || p.product?.id === productId.value))
    .map((p) => ({ value: String(p.id), label: `${adding.value && p.product ? `${systemName(p.product)} · ` : ''}${p.name} — ${priceLabel(p, t)}` })),
])

// One modal for the three forms that pick or date a plan.
type Mode = 'subscribe' | 'change' | 'end'
const mode = ref<Mode | null>(null)
const form = ref({ plan_id: '', status: 'active' as 'trial' | 'active', start_on: '', end_on: '', notes: '' })
const busy = ref(false)
const formError = ref<ApiRequestError | null>(null)

async function open(m: Mode, add = false): Promise<void> {
  adding.value = add
  formError.value = null
  form.value = { plan_id: '', status: 'active', start_on: '', end_on: m === 'end' ? (live.value?.end_on ?? '') : '', notes: m === 'end' ? (live.value?.notes ?? '') : '' }
  if (m !== 'end') await loadPlans()
  mode.value = m
}

const title = computed(() =>
  mode.value === 'subscribe' ? (adding.value ? t('bo.billing.school.addSystem') : t('bo.billing.school.subscribe')) : mode.value === 'change' ? t('bo.billing.school.changePlan') : t('bo.billing.school.editEnd'),
)

async function submit(): Promise<void> {
  busy.value = true
  formError.value = null
  const f = form.value
  try {
    if (mode.value === 'subscribe') {
      // The new subscription's system is shown next.
      productId.value = plans.value.find((p) => p.id === Number(f.plan_id))?.product?.id ?? productId.value
      billing.value = await billingApi.subscribe(props.school.id, {
        plan_id: Number(f.plan_id),
        status: f.status,
        start_on: f.start_on || null,
        end_on: f.end_on || null,
        notes: f.notes.trim() || null,
      })
    } else if (mode.value === 'change') {
      billing.value = await billingApi.changePlan(props.school.id, { plan_id: Number(f.plan_id), notes: f.notes.trim() || null })
    } else {
      billing.value = await billingApi.updateSubscription(props.school.id, { end_on: f.end_on || null, notes: f.notes.trim() || null, product_id: productId.value })
    }
    mode.value = null
    emit('changed')
  } catch (e) {
    formError.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    busy.value = false
  }
}

const cancelOpen = ref(false)
const cancelBusy = ref(false)
const cancelError = ref<string | undefined>(undefined)

async function confirmCancel(reason: string): Promise<void> {
  cancelBusy.value = true
  cancelError.value = undefined
  try {
    billing.value = await billingApi.cancel(props.school.id, reason, productId.value)
    cancelOpen.value = false
    emit('changed')
  } catch (e) {
    cancelError.value = e instanceof ApiRequestError ? (e.fieldError('reason') ?? e.message) : t('bo.common.failed')
  } finally {
    cancelBusy.value = false
  }
}

const statusOptions = computed(() => [
  { value: 'active', label: t('bo.billing.statuses.active') },
  { value: 'trial', label: t('bo.billing.statuses.trial') },
])

onMounted(load)
</script>

<template>
  <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-semibold text-neutral-900">{{ t('bo.billing.title') }}</h2>
      <BaseBadge v-if="billing && billing.flag !== 'none'" :variant="flagVariant[billing.flag]">{{ t(`bo.billing.flags.${billing.flag}`) }}</BaseBadge>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-3">{{ error }}</BaseAlert>

    <!-- The customer's systems: one at a time below. -->
    <div v-if="billing && (billing.systems.length > 1 || canManage)" class="mb-4 flex flex-wrap items-center gap-2">
      <button
        v-for="s in billing.systems"
        :key="s.product.id"
        type="button"
        class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm"
        :class="s.product.id === productId ? 'border-primary-500 bg-primary-50 font-medium text-neutral-900' : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50'"
        @click="showSystem(s.product.id)"
      >
        {{ systemName(s.product) }}
        <span v-if="s.flag !== 'none'" class="h-2 w-2 rounded-full" :class="s.flag === 'expiring' ? 'bg-amber-500' : s.flag === 'expired' ? 'bg-red-500' : 'bg-neutral-400'" :title="t(`bo.billing.flags.${s.flag}`)" />
      </button>
      <BaseButton v-if="canManage" size="sm" variant="ghost" @click="open('subscribe', true)">+ {{ t('bo.billing.school.addSystem') }}</BaseButton>
    </div>

    <template v-if="billing">
      <div v-if="live?.plan" class="space-y-1">
        <div class="flex flex-wrap items-center gap-2">
          <p class="text-lg font-semibold text-neutral-900">{{ live.plan.name }}</p>
          <BaseBadge :variant="live.status === 'trial' ? 'primary' : 'success'">{{ t(`bo.billing.statuses.${live.status}`) }}</BaseBadge>
        </div>
        <p class="text-sm text-neutral-600">{{ priceLabel(live.plan, t) }} · {{ studentsLabel(live.plan.max_students, t) }}</p>
        <p class="text-sm text-neutral-800">
          {{ t('bo.billing.school.ends') }}: <span class="font-medium">{{ live.end_on ? formatDate(live.end_on) : t('bo.billing.school.noEnd') }}</span>
          <span v-if="live.end_on" class="text-neutral-500"> · {{ endLabel(live, t) }}</span>
        </p>
        <p v-if="live.notes" class="text-sm whitespace-pre-line text-neutral-500">{{ live.notes }}</p>
      </div>
      <p v-else class="text-sm text-neutral-600">{{ t('bo.billing.school.noPlan') }}</p>

      <div v-if="billing.usage" class="mt-3">
        <div class="flex items-baseline justify-between gap-2 text-sm">
          <span class="text-neutral-600">{{ t('bo.billing.school.students') }}</span>
          <span class="font-medium text-neutral-900">
            {{
              billing.usage.limit === null
                ? t('bo.billing.school.studentsNoLimit', { active: billing.usage.active.toLocaleString() })
                : t('bo.billing.school.studentsOf', { active: billing.usage.active.toLocaleString(), limit: billing.usage.limit.toLocaleString() })
            }}
          </span>
        </div>
        <template v-if="usageShare !== null">
          <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-neutral-100">
            <div class="h-full rounded-full" :class="usageBar" :style="{ width: `${Math.min(100, usageShare * 100)}%` }" />
          </div>
          <p v-if="usageShare >= 1" class="mt-1 text-xs text-red-600">{{ t('bo.billing.school.atLimit') }}</p>
          <p v-else-if="usageShare >= 0.9" class="mt-1 text-xs text-amber-700">{{ t('bo.billing.school.nearLimit') }}</p>
        </template>
      </div>
      <div v-if="canManage" class="mt-4 flex flex-wrap gap-2">
        <BaseButton v-if="payable" size="sm" @click="payOpen = true">{{ t('bo.billing.payments.record') }}</BaseButton>
        <template v-if="live">
          <BaseButton size="sm" variant="outline" @click="open('change')">{{ t('bo.billing.school.changePlan') }}</BaseButton>
          <BaseButton size="sm" variant="outline" @click="open('end')">{{ t('bo.billing.school.editEnd') }}</BaseButton>
          <BaseButton size="sm" variant="ghost" @click="cancelOpen = true">{{ t('bo.billing.school.cancel') }}</BaseButton>
        </template>
        <BaseButton v-else size="sm" @click="open('subscribe')">{{ t('bo.billing.school.subscribe') }}</BaseButton>
      </div>

      <div v-if="payments.length > 0" class="mt-4 border-t border-neutral-100 pt-3">
        <p class="mb-2 text-sm font-medium text-neutral-700">{{ t('bo.billing.payments.recent') }}</p>
        <ul class="space-y-2">
          <li v-for="p in payments" :key="p.id" class="flex items-start justify-between gap-2 text-sm" :class="{ 'opacity-60': p.void }">
            <div class="min-w-0">
              <a :href="paymentsApi.receiptUrl(p.id)" class="font-mono text-xs text-secondary-700 hover:underline">{{ p.receipt_number }}</a>
              <span v-if="p.void" class="ml-1 text-xs text-red-600">{{ t('bo.billing.payments.voided') }}</span>
              <p class="text-xs text-neutral-500">{{ formatDate(p.paid_on) }} · {{ t(`bo.billing.payments.methods.${p.method}`) }}</p>
            </div>
            <span class="font-medium whitespace-nowrap" :class="{ 'line-through': p.void }">{{ formatMoney(Number(p.amount), p.currency) }}</span>
          </li>
        </ul>
        <RouterLink v-if="paymentCount > payments.length" to="/billing/payments" class="mt-2 inline-block text-sm text-secondary-700 hover:underline">
          {{ t('bo.billing.payments.all') }} ({{ paymentCount }})
        </RouterLink>
      </div>

      <details v-if="billing.history.length > 1" class="mt-4 border-t border-neutral-100 pt-3">
        <summary class="cursor-pointer text-sm font-medium text-neutral-700">{{ t('bo.billing.school.history') }} ({{ billing.history.length }})</summary>
        <ol class="mt-3 space-y-3">
          <li v-for="row in billing.history" :key="row.id" class="text-sm">
            <p class="text-neutral-800">
              {{ row.plan?.name ?? '—' }} · <span class="text-neutral-600">{{ t(`bo.billing.statuses.${row.status}`) }}</span>
            </p>
            <p class="text-xs text-neutral-500">
              {{ formatDate(row.start_on) }} → {{ row.end_on ? formatDate(row.end_on) : t('bo.billing.school.noEnd') }}
              <template v-if="row.created_by"> · {{ t('bo.billing.school.by', { name: row.created_by }) }}</template>
            </p>
          </li>
        </ol>
      </details>
    </template>

    <BaseModal :model-value="mode !== null" :title="title" size="sm" @update:model-value="mode = null">
      <form id="bo-billing" class="space-y-4" @submit.prevent="submit">
        <BaseAlert v-if="formError && !formError.errors" variant="danger">{{ formError.message }}</BaseAlert>
        <p v-if="mode === 'change'" class="text-sm text-neutral-600">{{ t('bo.billing.school.changeHint') }}</p>
        <p v-if="adding && planOptions.length === 1" class="text-sm text-amber-700">{{ t('bo.billing.school.noOtherPlans') }}</p>

        <BaseSelect
          v-if="mode !== 'end'"
          v-model="form.plan_id"
          :options="planOptions"
          :label="t('bo.billing.school.plan')"
          :error="formError?.fieldError('plan_id')"
          required
        />
        <template v-if="mode === 'subscribe'">
          <BaseSelect v-model="form.status" :options="statusOptions" :label="t('bo.billing.school.statusField')" />
          <BaseInput v-model="form.start_on" type="date" :label="t('bo.billing.school.startOn')" :error="formError?.fieldError('start_on')" />
          <BaseInput
            v-model="form.end_on"
            type="date"
            :label="t('bo.billing.school.endOn')"
            :hint="t('bo.billing.school.endOnHint')"
            :error="formError?.fieldError('end_on')"
          />
        </template>
        <BaseInput
          v-if="mode === 'end'"
          v-model="form.end_on"
          type="date"
          :label="t('bo.billing.school.endOn')"
          :hint="t('bo.billing.school.endOnClear')"
          :error="formError?.fieldError('end_on')"
        />
        <BaseInput v-model="form.notes" :label="t('bo.billing.school.notes')" :error="formError?.fieldError('notes')" />
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="mode = null">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="bo-billing" :loading="busy">{{ t('bo.common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <RecordPaymentModal v-model="payOpen" :school-id="school.id" :product-id="productId" @recorded="load(), emit('changed')" />

    <ReasonModal
      v-model="cancelOpen"
      :title="t('bo.billing.school.cancel')"
      :hint="t('bo.billing.school.cancelHint')"
      :confirm-label="t('bo.billing.school.cancel')"
      :busy="cancelBusy"
      :error="cancelError"
      @confirm="confirmCancel"
    />
  </section>
</template>

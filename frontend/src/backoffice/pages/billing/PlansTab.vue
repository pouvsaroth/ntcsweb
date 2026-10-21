<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

import BaseSelect from '@/components/ui/BaseSelect.vue'

import { billingApi, type Plan, type PlanModule, type ProductChoice } from '../../api'
import { bigPrice, moduleLabel, periodLabel, studentsLabel } from '../../billing'
import PlanFormModal from '../../components/PlanFormModal.vue'
import { useSession } from '../../session'

/** The plans a school can be put on. One card per plan, so phone and desktop read the same. */
const { t, locale } = useI18n()
const session = useSession()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => session.can('platform.billing.manage'))

const plans = ref<Plan[]>([])
const modules = ref<PlanModule[]>([])
const products = ref<ProductChoice[]>([])
/** Show one system's plans, or all. */
const system = ref('')
const systemOptions = computed(() => [
  { value: '', label: t('bo.billing.plans.allSystems') },
  ...products.value.map((p) => ({ value: String(p.id), label: locale.value === 'km' && p.name_km ? p.name_km : p.name })),
])
const shown = computed(() => (system.value ? plans.value.filter((p) => String(p.product?.id) === system.value) : plans.value))
const systemName = (plan: Plan) => (plan.product ? (locale.value === 'km' && plan.product.name_km ? plan.product.name_km : plan.product.name) : '')
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

async function load(): Promise<void> {
  try {
    const data = await billingApi.plans()
    plans.value = data.plans
    modules.value = data.modules
    products.value = data.products
    error.value = null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
}

const formOpen = ref(false)
const editing = ref<Plan | null>(null)

function openForm(plan: Plan | null): void {
  editing.value = plan
  formOpen.value = true
}

/** The card as the website shows it: Khmer to a Khmer reader, falling back to the other language. */
const km = computed(() => locale.value === 'km')
const nameOf = (plan: Plan) => (km.value ? plan.name_km || plan.name : plan.name)
const taglineOf = (plan: Plan) => (km.value ? plan.tagline_km || plan.tagline_en : plan.tagline_en || plan.tagline_km)
const lines = (text: string | null) =>
  (text ?? '')
    .split('\n')
    .map((l) => l.replace(/^[\s\-•·*]+/, '').trim())
    .filter(Boolean)
function featuresOf(plan: Plan): string[] {
  const kmLines = lines(plan.features_km)
  const enLines = lines(plan.features_en)
  return km.value ? (kmLines.length ? kmLines : enLines) : enLines.length ? enLines : kmLines
}
/** Its modules as chips; "Academics 8/16" when only some of a module's menus are in. */
function chipsOf(plan: Plan): string[] {
  return modules.value
    .filter((m) => plan.modules.includes(m.code))
    .map((m) => {
      const kept = plan.menus === null ? m.groups.length : m.groups.filter((g) => plan.menus!.includes(g)).length
      return kept < m.groups.length ? `${moduleLabel(m, locale.value)} ${kept}/${m.groups.length}` : moduleLabel(m, locale.value)
    })
}

async function remove(plan: Plan): Promise<void> {
  const question = plan.used_count === 0 ? 'bo.billing.plans.deleteConfirm' : 'bo.billing.plans.deactivateConfirm'
  if (!(await confirmDialog.confirm(t(question, { name: plan.name })))) return
  notice.value = null
  try {
    const { deleted } = await billingApi.removePlan(plan.id)
    if (!deleted) notice.value = t('bo.billing.plans.deactivated')
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

async function reactivate(plan: Plan): Promise<void> {
  try {
    await billingApi.updatePlan(plan.id, { status: 'active' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <BaseSelect v-model="system" :options="systemOptions" class="w-full sm:w-72" />
      <BaseButton v-if="canManage" @click="openForm(null)">{{ t('bo.billing.plans.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="info" class="mb-4">{{ notice }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="shown.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('bo.billing.plans.empty') }}
    </p>

    <div v-else class="grid gap-x-4 gap-y-6 pt-3 md:grid-cols-2 xl:grid-cols-3">
      <!-- The card as visitors see it on the website, then the staff-only details. -->
      <section
        v-for="plan in shown"
        :key="plan.id"
        class="relative flex flex-col rounded-2xl border bg-white p-5 shadow-sm"
        :class="[plan.recommended ? 'border-primary-500 ring-2 ring-primary-500' : 'border-neutral-200', { 'opacity-60': plan.status === 'inactive' }]"
      >
        <span
          v-if="plan.recommended"
          class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-primary-500 px-3 py-0.5 text-xs font-semibold whitespace-nowrap text-secondary-900"
        >
          {{ t('bo.billing.plans.web.recommended') }}
        </span>

        <h2 class="text-lg font-semibold text-neutral-900">{{ nameOf(plan) }}</h2>
        <p class="mt-2 text-3xl font-bold text-neutral-900">{{ bigPrice(plan) }}</p>
        <p class="text-sm text-neutral-500">{{ periodLabel(plan, t) }}</p>
        <p v-if="taglineOf(plan)" class="mt-2 text-sm text-neutral-600">{{ taglineOf(plan) }}</p>

        <div class="mt-4 border-t border-neutral-100 pt-3">
          <p class="text-xs font-medium tracking-wide text-neutral-500 uppercase">{{ t('bo.billing.plans.includes') }}</p>
          <div class="mt-2 flex flex-wrap gap-1.5">
            <span v-for="c in chipsOf(plan)" :key="c" class="rounded-full bg-secondary-50 px-2.5 py-0.5 text-xs font-medium text-secondary-800">{{ c }}</span>
            <span v-if="chipsOf(plan).length === 0" class="text-xs text-neutral-400">{{ t('bo.billing.plans.noModules') }}</span>
          </div>
        </div>
        <ul v-if="featuresOf(plan).length" class="mt-3 space-y-1.5 border-t border-neutral-100 pt-3">
          <li v-for="(f, i) in featuresOf(plan)" :key="i" class="flex items-start gap-2 text-sm text-neutral-800">
            <span class="mt-0.5 text-primary-600" aria-hidden="true">✓</span><span>{{ f }}</span>
          </li>
        </ul>

        <!-- Staff only -->
        <div class="mt-auto pt-4">
          <div class="space-y-1.5 rounded-lg bg-neutral-50 p-3 text-xs text-neutral-600">
            <p>
              <span class="font-mono text-neutral-500">{{ plan.code }}</span><template v-if="plan.product"> · {{ systemName(plan) }}</template>
            </p>
            <div class="flex flex-wrap gap-1">
              <BaseBadge v-if="plan.is_legacy">{{ t('bo.billing.plans.legacy') }}</BaseBadge>
              <BaseBadge v-if="plan.status === 'inactive'" variant="warning">{{ t('bo.billing.plans.inactive') }}</BaseBadge>
              <BaseBadge :variant="plan.placement === 'dedicated' ? 'warning' : 'neutral'">{{ t(`bo.billing.plans.form.db.${plan.placement}`) }}</BaseBadge>
              <BaseBadge :variant="plan.show_on_website ? 'primary' : 'neutral'">
                {{ plan.show_on_website ? t('bo.billing.plans.web.shown') : t('bo.billing.plans.form.notShown') }}
              </BaseBadge>
            </div>
            <p>{{ studentsLabel(plan.max_students, t) }}</p>
            <p>
              {{ t('bo.billing.plans.liveCount', { n: plan.live_count }) }}<template v-if="plan.used_count === 0"> · {{ t('bo.billing.plans.neverUsed') }}</template>
            </p>
          </div>
        </div>

        <div v-if="canManage" class="mt-3 flex flex-wrap gap-2">
          <BaseButton size="sm" variant="outline" @click="openForm(plan)">{{ t('bo.billing.plans.edit') }}</BaseButton>
          <BaseButton v-if="plan.status === 'inactive'" size="sm" variant="outline" @click="reactivate(plan)">{{ t('bo.billing.plans.reactivate') }}</BaseButton>
          <!-- Never used by a customer: deleted for good (active or not). Used: only deactivated. -->
          <BaseButton v-if="!plan.is_legacy && plan.used_count === 0" size="sm" variant="ghost" class="!text-red-600" @click="remove(plan)">
            {{ t('bo.billing.plans.delete') }}
          </BaseButton>
          <BaseButton v-else-if="!plan.is_legacy && plan.status === 'active'" size="sm" variant="ghost" @click="remove(plan)">
            {{ t('bo.billing.plans.deactivate') }}
          </BaseButton>
        </div>
      </section>
    </div>

    <PlanFormModal
      v-model="formOpen"
      :plan="editing"
      :modules="modules"
      :products="products"
      :default-product-id="system ? Number(system) : null"
      @saved="load"
    />
  </div>
</template>

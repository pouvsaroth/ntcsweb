<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

import { billingApi, type Plan, type PlanModule } from '../../api'
import { moduleLabel, priceLabel, studentsLabel } from '../../billing'
import PlanFormModal from '../../components/PlanFormModal.vue'
import { useSession } from '../../session'

/** The plans a school can be put on. One card per plan, so phone and desktop read the same. */
const { t, locale } = useI18n()
const session = useSession()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => session.can('platform.billing.manage'))

const plans = ref<Plan[]>([])
const modules = ref<PlanModule[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

async function load(): Promise<void> {
  try {
    const data = await billingApi.plans()
    plans.value = data.plans
    modules.value = data.modules
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

function modulesOf(plan: Plan): string {
  if (plan.modules.length === modules.value.length && plan.menus === null) return t('bo.billing.plans.allModules')
  if (plan.modules.length === 0) return t('bo.billing.plans.noModules')
  return modules.value
    .filter((m) => plan.modules.includes(m.code))
    .map((m) => {
      // Some menus only: "Academics (8/16)".
      const kept = plan.menus === null ? m.groups.length : m.groups.filter((g) => plan.menus!.includes(g)).length
      return kept < m.groups.length ? `${moduleLabel(m, locale.value)} (${kept}/${m.groups.length})` : moduleLabel(m, locale.value)
    })
    .join(' · ')
}

async function remove(plan: Plan): Promise<void> {
  if (!(await confirmDialog.confirm(t('bo.billing.plans.removeConfirm', { name: plan.name })))) return
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
    <div class="mb-4 flex justify-end">
      <BaseButton v-if="canManage" @click="openForm(null)">{{ t('bo.billing.plans.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="info" class="mb-4">{{ notice }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="plans.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('bo.billing.plans.empty') }}
    </p>

    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <section
        v-for="plan in plans"
        :key="plan.id"
        class="flex flex-col rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]"
        :class="{ 'opacity-60': plan.status === 'inactive' }"
      >
        <div class="mb-1 flex flex-wrap items-center gap-2">
          <h2 class="font-semibold text-neutral-900">{{ plan.name }}</h2>
          <BaseBadge v-if="plan.is_legacy">{{ t('bo.billing.plans.legacy') }}</BaseBadge>
          <BaseBadge v-if="plan.status === 'inactive'" variant="warning">{{ t('bo.billing.plans.inactive') }}</BaseBadge>
          <BaseBadge v-if="plan.show_on_website" variant="primary">{{ t('bo.billing.plans.web.shown') }}</BaseBadge>
          <BaseBadge v-if="plan.recommended" variant="success">{{ t('bo.billing.plans.web.recommended') }}</BaseBadge>
        </div>
        <p class="font-mono text-xs text-neutral-400">{{ plan.code }}</p>
        <p class="mt-3 text-lg font-semibold text-neutral-900">{{ priceLabel(plan, t) }}</p>
        <p class="text-sm text-neutral-600">{{ studentsLabel(plan.max_students, t) }}</p>
        <p v-if="plan.description" class="mt-2 text-sm text-neutral-600">{{ plan.description }}</p>
        <p class="mt-3 text-xs font-medium tracking-wide text-neutral-500 uppercase">{{ t('bo.billing.plans.modules') }}</p>
        <p class="text-sm text-neutral-700">{{ modulesOf(plan) }}</p>
        <p class="mt-3 text-xs text-neutral-500">{{ t('bo.billing.plans.liveCount', { n: plan.live_count }) }}</p>

        <div v-if="canManage" class="mt-4 flex flex-wrap gap-2 border-t border-neutral-100 pt-4">
          <BaseButton size="sm" variant="outline" @click="openForm(plan)">{{ t('bo.billing.plans.edit') }}</BaseButton>
          <BaseButton v-if="plan.status === 'inactive'" size="sm" variant="outline" @click="reactivate(plan)">{{ t('bo.billing.plans.reactivate') }}</BaseButton>
          <BaseButton v-else-if="!plan.is_legacy" size="sm" variant="ghost" @click="remove(plan)">{{ t('bo.billing.plans.remove') }}</BaseButton>
        </div>
      </section>
    </div>

    <PlanFormModal v-model="formOpen" :plan="editing" :modules="modules" @saved="load" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

import { billingApi, type PlanModule, type Product } from '../../api'
import { moduleLabel } from '../../billing'
import ProductFormModal from '../../components/ProductFormModal.vue'
import { useSession } from '../../session'

/**
 * Billing → Systems (docs/backoffice/stage-9-products.md): what the platform
 * sells. Each system is built from modules; its plans offer only those.
 */
const { t, locale } = useI18n()
const session = useSession()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => session.can('platform.billing.manage'))

const products = ref<Product[]>([])
const modules = ref<PlanModule[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

async function load(): Promise<void> {
  try {
    const data = await billingApi.products()
    products.value = data.products
    modules.value = data.modules
    error.value = null
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
}

const formOpen = ref(false)
const editing = ref<Product | null>(null)
function openForm(p: Product | null): void {
  editing.value = p
  formOpen.value = true
}

const nameOf = (p: Product) => (locale.value === 'km' && p.name_km ? p.name_km : p.name)
const modulesOf = (p: Product) =>
  modules.value
    .filter((m) => p.modules.includes(m.code))
    .map((m) => moduleLabel(m, locale.value))
    .join(' · ')

async function remove(p: Product): Promise<void> {
  if (!(await confirmDialog.confirm(t('bo.billing.systems.removeConfirm', { name: p.name })))) return
  notice.value = null
  try {
    const { deleted } = await billingApi.removeProduct(p.id)
    if (!deleted) notice.value = t('bo.billing.systems.deactivated')
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

async function reactivate(p: Product): Promise<void> {
  try {
    await billingApi.updateProduct(p.id, { status: 'active' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">{{ t('bo.billing.systems.hint') }}</p>
      <BaseButton v-if="canManage" @click="openForm(null)">{{ t('bo.billing.systems.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="info" class="mb-4">{{ notice }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <section
        v-for="p in products"
        :key="p.id"
        class="flex flex-col rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]"
        :class="{ 'opacity-60': p.status === 'inactive' }"
      >
        <div class="flex items-start gap-3">
          <img v-if="p.logo_url" :src="p.logo_url" alt="" class="h-12 w-12 shrink-0 rounded object-contain" />
          <div v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded bg-neutral-100 text-xl" aria-hidden="true">🧩</div>
          <div class="min-w-0">
            <h2 class="font-semibold text-neutral-900">{{ nameOf(p) }}</h2>
            <p class="font-mono text-xs text-neutral-400">{{ p.code }}</p>
          </div>
          <BaseBadge v-if="p.status === 'inactive'" variant="warning" class="ml-auto">{{ t('bo.billing.plans.inactive') }}</BaseBadge>
        </div>
        <p v-if="locale === 'km' ? p.description_km || p.description : p.description" class="mt-3 text-sm text-neutral-600">
          {{ locale === 'km' ? p.description_km || p.description : p.description }}
        </p>
        <p class="mt-3 text-xs font-medium tracking-wide text-neutral-500 uppercase">{{ t('bo.billing.systems.modules') }}</p>
        <p class="text-sm text-neutral-700">{{ modulesOf(p) }}</p>
        <p class="mt-3 text-xs text-neutral-500">{{ t('bo.billing.systems.plansCount', { n: p.plans_count }) }}</p>

        <div v-if="canManage" class="mt-4 flex flex-wrap gap-2 border-t border-neutral-100 pt-4">
          <BaseButton size="sm" variant="outline" @click="openForm(p)">{{ t('bo.billing.systems.edit') }}</BaseButton>
          <BaseButton v-if="p.status === 'inactive'" size="sm" variant="outline" @click="reactivate(p)">{{ t('bo.billing.plans.reactivate') }}</BaseButton>
          <BaseButton v-else-if="!p.is_school" size="sm" variant="ghost" @click="remove(p)">{{ t('bo.billing.plans.remove') }}</BaseButton>
        </div>
      </section>
    </div>

    <ProductFormModal v-model="formOpen" :product="editing" :modules="modules" @saved="load" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import FundProviderFormModal from '@/components/admin/FundProviderFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { lookupsService } from '@/services/lookups'
import { fundProvidersService, type FundProvider } from '@/services/fundProviders'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { genderLabel as builtInGenderLabel } from '@/utils/gender'

// Fund > Providers: the people who give money to the funds, with what each has given.
const { t, locale } = useI18n()

/** code -> translated label, from the same GENDER list the form uses. */
const genderLabels = ref<Record<string, string>>({})

async function loadGenderLabels() {
  const options = await lookupsService.values('GENDER', locale.value)
  genderLabels.value = Object.fromEntries(options.map((option) => [option.code, option.name]))
}

function genderLabel(gender: string | null): string {
  if (!gender) return '—'
  return genderLabels.value[gender] ?? builtInGenderLabel(gender)
}

watch(locale, loadGenderLabels)
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canCreate = computed(() => auth.can('funds.create'))
const canUpdate = computed(() => auth.can('funds.update'))
const canDelete = computed(() => auth.can('funds.delete'))
const canManage = computed(() => canUpdate.value || canDelete.value)

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<FundProvider>((query) => fundProvidersService.list(query))

const statusFilter = ref('true')
const statusOptions = computed(() => [
  { value: 'true', label: t('admin.fundProviders.active') },
  { value: 'false', label: t('admin.fundProviders.inactive') },
  { value: '', label: t('admin.fundProviders.filterAll') },
])
function onStatus(value: string) {
  statusFilter.value = value
  setFilter('is_active', value || undefined)
}

const columns = computed(() => [
  { key: 'code', label: t('admin.fundProviders.code') },
  { key: 'name', label: t('admin.fundProviders.name') },
  { key: 'gender', label: t('admin.fundProviders.gender') },
  { key: 'phone', label: t('admin.fundProviders.phone') },
  { key: 'given', label: t('admin.fundProviders.given'), align: 'text-right' },
  { key: 'is_active', label: t('admin.fundProviders.status') },
  ...(canManage.value ? [{ key: 'actions', label: '', align: 'text-right' }] : []),
])

/** "$700.00 · 40,000 ៛" — only the currencies given; a dash when nothing yet. */
function givenText(row: FundProvider): string {
  const parts: string[] = []
  if (row.given?.USD) parts.push(formatMoney(row.given.USD, 'USD'))
  if (row.given?.KHR) parts.push(formatMoney(row.given.KHR, 'KHR'))
  return parts.length ? parts.join(' · ') : '—'
}

const modalOpen = ref(false)
const editing = ref<FundProvider | null>(null)
const deleteError = ref<string | null>(null)

function open(provider: FundProvider | null) {
  editing.value = provider
  modalOpen.value = true
}

async function remove(provider: FundProvider) {
  if (!(await confirmDialog.confirm({ message: t('admin.fundProviders.deleteConfirm'), danger: true }))) return
  deleteError.value = null
  try {
    await fundProvidersService.remove(provider.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.fundProviders.deleteFailed')
  }
}

onMounted(() => {
  setFilter('is_active', 'true')
  void loadGenderLabels()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.fundProviders.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.fundProviders.subtitle') }}</p>
    </div>

    <div class="mb-6 flex flex-wrap items-center gap-3">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-36" :model-value="statusFilter" :options="statusOptions" @update:model-value="onStatus" />
      <BaseButton v-if="canCreate" class="ml-auto shrink-0" @click="open(null)">{{ t('admin.fundProviders.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.fundProviders.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.name }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">{{ row.code }}<template v-if="row.gender"> · {{ genderLabel(row.gender) }}</template><template v-if="row.phone"> · {{ row.phone }}</template></p>
              <p class="mt-1 text-sm font-semibold tabular-nums text-green-700">{{ givenText(row) }}</p>
            </div>
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
              {{ row.is_active ? t('admin.fundProviders.active') : t('admin.fundProviders.inactive') }}
            </BaseBadge>
          </div>
          <div v-if="canManage" class="mt-2 flex items-center justify-end gap-3">
            <EditIconButton v-if="canUpdate" @click="open(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
              {{ t('admin.fundProviders.delete') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.fundProviders.empty')">
      <template #cell-code="{ row }"><span class="tabular-nums text-neutral-600">{{ row.code }}</span></template>
      <template #cell-name="{ row }">
        <p class="font-medium text-neutral-900">{{ row.name }}</p>
        <p v-if="row.address" class="max-w-xs truncate text-xs text-neutral-500">{{ row.address }}</p>
      </template>
      <template #cell-gender="{ row }">{{ genderLabel(row.gender) }}</template>
      <template #cell-phone="{ row }">{{ row.phone ?? '—' }}</template>
      <template #cell-given="{ row }"><span class="font-semibold tabular-nums text-green-700">{{ givenText(row) }}</span></template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.fundProviders.active') : t('admin.fundProviders.inactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton v-if="canUpdate" @click="open(row)" />
          <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.fundProviders.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <FundProviderFormModal v-model="modalOpen" :provider="editing" @saved="fetch()" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatDateTime } from '@/utils/date'

import { supportApi, type SupportView } from '../../api'
import SupportViewDetail from '../../components/SupportViewDetail.vue'
import { stateVariant } from '../../support'

/** Every support view of every school, newest first; open ones can be ended. */
const { t } = useI18n()
const route = useRoute()

const { items, meta, loading, error, search, setSearch, setPage, setFilter, fetch } = usePaginatedResource<SupportView>((query) => supportApi.list(query))

const state = ref('')
const stateOptions = computed(() => [
  { value: '', label: t('bo.support.allViews') },
  { value: 'open', label: t('bo.support.openOnly') },
])

function filterState(value: string): void {
  state.value = value
  setFilter('state', value || undefined)
}

const schoolFilter = ref(typeof route.query.school === 'string' ? route.query.school : '')

function clearSchool(): void {
  schoolFilter.value = ''
  setFilter('tenant_id', undefined)
}

const columns = computed(() => [
  { key: 'started_at', label: t('bo.support.started') },
  { key: 'school', label: t('bo.support.school') },
  { key: 'who', label: t('bo.support.staffAndUser') },
  { key: 'reason', label: t('bo.support.reason') },
  { key: 'state', label: t('bo.support.state') },
])

const shownId = ref<number | null>(null)

onMounted(() => {
  if (schoolFilter.value) setFilter('tenant_id', schoolFilter.value)
  else void fetch()
})
</script>

<template>
  <div>
    <div class="mb-4 grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <BaseInput :model-value="search" :placeholder="t('bo.support.search')" @update:model-value="setSearch" />
      <BaseSelect :model-value="state" :options="stateOptions" @update:model-value="filterState" />
    </div>
    <p v-if="schoolFilter" class="mb-3 text-sm text-neutral-600">
      {{ t('bo.support.filteredToSchool') }}
      <button type="button" class="ml-1 text-secondary-700 hover:underline" @click="clearSchool">{{ t('bo.support.showAll') }}</button>
    </p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.support.empty') }}
      </p>
      <div v-else class="space-y-2">
        <button
          v-for="row in items"
          :key="row.id"
          type="button"
          class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]"
          @click="shownId = row.id"
        >
          <div class="flex items-start justify-between gap-2">
            <p class="min-w-0 truncate font-medium text-neutral-900">{{ row.school?.name }}</p>
            <BaseBadge :variant="stateVariant[row.state]">{{ t(`bo.support.states.${row.state}`) }}</BaseBadge>
          </div>
          <p class="mt-1 truncate text-sm text-neutral-700">{{ row.reason }}</p>
          <p class="mt-1 text-xs text-neutral-500">
            {{ row.staff?.name }} → {{ row.user?.name }} · {{ formatDateTime(row.started_at) }} · {{ t('bo.support.pagesShort', { n: row.events_count }) }}
          </p>
        </button>
      </div>
    </div>

    <!-- Tablet / desktop: table -->
    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('bo.support.empty')">
        <template #cell-started_at="{ row }">
          <button type="button" class="text-left text-secondary-700 hover:underline" @click="shownId = row.id">{{ formatDateTime(row.started_at) }}</button>
          <p class="text-xs text-neutral-500">{{ t('bo.support.pagesShort', { n: row.events_count }) }}</p>
        </template>
        <template #cell-school="{ row }">
          <RouterLink v-if="row.school" :to="`/schools/${row.school.id}`" class="text-secondary-700 hover:underline">{{ row.school.name }}</RouterLink>
        </template>
        <template #cell-who="{ row }">
          {{ row.staff?.name ?? '—' }}
          <p class="text-xs text-neutral-500">{{ t('bo.support.asUser', { name: row.user?.name ?? '—' }) }}</p>
        </template>
        <template #cell-reason="{ row }">
          <span class="line-clamp-2 break-words">{{ row.reason }}</span>
        </template>
        <template #cell-state="{ row }">
          <BaseBadge :variant="stateVariant[row.state]">{{ t(`bo.support.states.${row.state}`) }}</BaseBadge>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />

    <SupportViewDetail :view-id="shownId" @close="shownId = null" @ended="fetch" />
  </div>
</template>

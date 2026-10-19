<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { ApiRequestError } from '@/types/api'

import { websiteApi, type WebsiteImage, type WebsiteImageKind } from '../../api'
import WebsiteImageModal from '../../components/WebsiteImageModal.vue'
import { useSession } from '../../session'
import { imageLabelField } from '../../website'

/** Home slides, gallery or promotions: pictures in order, each shown or hidden on the website. */
const props = defineProps<{ kind: WebsiteImageKind }>()

const { t } = useI18n()
const session = useSession()
const canManage = computed(() => session.can('platform.website.manage'))

const { items, meta, loading, error, setPage, setSort, sort, fetch } = usePaginatedResource<WebsiteImage>((query) => websiteApi.images(props.kind, query))

const columns = computed(() => [
  { key: 'image_url', label: t('bo.website.picture') },
  { key: 'label', label: t(`bo.website.fields.${imageLabelField[props.kind]}`) },
  { key: 'sort_order', label: t('bo.website.order'), sortable: true },
  { key: 'status', label: t('bo.website.status') },
  { key: 'actions', label: '' },
])

const label = (row: WebsiteImage) => row[imageLabelField[props.kind]] || '—'

const editing = ref<WebsiteImage | null>(null)
const modalOpen = ref(false)
function openAdd(): void {
  editing.value = null
  modalOpen.value = true
}
function openEdit(row: WebsiteImage): void {
  editing.value = row
  modalOpen.value = true
}

const removing = ref<WebsiteImage | null>(null)
const removeBusy = ref(false)
const removeError = ref<string | null>(null)
async function confirmRemove(): Promise<void> {
  if (!removing.value) return
  removeBusy.value = true
  removeError.value = null
  try {
    await websiteApi.removeImage(props.kind, removing.value.id)
    removing.value = null
    await fetch()
  } catch (e) {
    removeError.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    removeBusy.value = false
  }
}

onMounted(() => fetch())
watch(() => props.kind, () => fetch())
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">{{ t(`bo.website.${kind}.hint`) }}</p>
      <BaseButton v-if="canManage" @click="openAdd">{{ t(`bo.website.${kind}.add`) }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.website.empty') }}
      </p>
      <div v-else class="space-y-3">
        <div v-for="row in items" :key="row.id" class="overflow-hidden rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card]">
          <img :src="row.image_url" alt="" class="aspect-video w-full object-cover" />
          <div class="p-3">
            <div class="flex items-start justify-between gap-2">
              <p class="min-w-0 break-words font-medium text-neutral-900">{{ label(row) }}</p>
              <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
                {{ row.status === 'active' ? t('bo.website.shown') : t('bo.website.hidden') }}
              </BaseBadge>
            </div>
            <p class="mt-1 text-xs text-neutral-500">{{ t('bo.website.orderN', { n: row.sort_order }) }}</p>
            <div v-if="canManage" class="mt-2 flex gap-4 text-sm">
              <button type="button" class="text-secondary-700 hover:underline" @click="openEdit(row)">{{ t('bo.website.edit') }}</button>
              <button type="button" class="text-red-600 hover:underline" @click="removing = row">{{ t('bo.website.remove') }}</button>
            </div>
          </div>
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
        :empty-message="t('bo.website.empty')"
        @sort="(col) => setSort(sort === col ? `-${col}` : col)"
      >
        <template #cell-image_url="{ row }">
          <img :src="row.image_url" alt="" class="h-14 w-24 rounded-md object-cover" />
        </template>
        <template #cell-label="{ row }">
          <span class="break-words text-neutral-900">{{ label(row) }}</span>
          <p v-if="row.subtitle" class="text-xs text-neutral-500">{{ row.subtitle }}</p>
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
            {{ row.status === 'active' ? t('bo.website.shown') : t('bo.website.hidden') }}
          </BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div v-if="canManage" class="flex justify-end gap-3 text-sm whitespace-nowrap">
            <button type="button" class="text-secondary-700 hover:underline" @click="openEdit(row)">{{ t('bo.website.edit') }}</button>
            <button type="button" class="text-red-600 hover:underline" @click="removing = row">{{ t('bo.website.remove') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />

    <WebsiteImageModal v-model="modalOpen" :kind="kind" :item="editing" @saved="fetch" />

    <BaseModal :model-value="removing !== null" :title="t('bo.website.removeTitle')" size="sm" @update:model-value="removing = null">
      <p class="text-sm text-neutral-600">{{ t('bo.website.removeText') }}</p>
      <BaseAlert v-if="removeError" variant="danger" class="mt-3">{{ removeError }}</BaseAlert>
      <template #footer>
        <BaseButton variant="ghost" @click="removing = null">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton variant="danger" :loading="removeBusy" @click="confirmRemove">{{ t('bo.website.remove') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import LanguageFormModal from '@/components/admin/LanguageFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { languagesService, type Language } from '@/services/languages'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<Language>((query) => languagesService.list(query))

const columns = [
  { key: 'code', label: t('admin.languages.columnCode') },
  { key: 'name', label: t('admin.languages.columnName') },
  { key: 'native_name', label: t('admin.languages.columnNativeName') },
  { key: 'is_default', label: t('admin.languages.columnDefault') },
  { key: 'is_active', label: t('admin.languages.columnStatus') },
  { key: 'actions', label: t('admin.languages.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingLanguage = ref<Language | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingLanguage.value = null
  modalOpen.value = true
}

function openEdit(language: Language) {
  editingLanguage.value = language
  modalOpen.value = true
}

async function remove(language: Language) {
  if (!window.confirm(t('admin.languages.deleteConfirm'))) return
  deleteError.value = null

  try {
    await languagesService.remove(language.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.languages.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.languages.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.languages.pageSubtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.languages.addLanguage') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.languages.emptyMessage')">
      <template #cell-is_default="{ row }">
        <BaseBadge v-if="row.is_default" variant="success">{{ t('admin.languages.default') }}</BaseBadge>
        <span v-else>—</span>
      </template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.languages.statusActive') : t('admin.languages.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.languages.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <LanguageFormModal v-model="modalOpen" :language="editingLanguage" @saved="fetch" />
  </div>
</template>

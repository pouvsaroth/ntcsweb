<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import LookupValueFormModal from '@/components/admin/LookupValueFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { type Language, languagesService } from '@/services/languages'
import { lookupCategoriesService, type LookupCategory } from '@/services/lookupCategories'
import { type LookupValue, lookupValuesService } from '@/services/lookupValues'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const route = useRoute()
const categoryId = Number(route.params.id)

const category = ref<LookupCategory | null>(null)
const languages = ref<Language[]>([])
const values = ref<LookupValue[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const deleteError = ref<string | null>(null)

const columns = computed(() => [
  { key: 'code', label: t('admin.lookupValues.columnCode') },
  ...languages.value.map((language) => ({ key: `lang_${language.code}`, label: language.native_name })),
  { key: 'is_active', label: t('admin.lookupValues.columnStatus') },
  { key: 'actions', label: t('admin.lookupValues.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingValue = ref<LookupValue | null>(null)

function openCreate() {
  editingValue.value = null
  modalOpen.value = true
}

function openEdit(value: LookupValue) {
  editingValue.value = value
  modalOpen.value = true
}

async function remove(value: LookupValue) {
  if (!window.confirm(t('admin.lookupValues.deleteConfirm'))) return
  deleteError.value = null

  try {
    await lookupValuesService.remove(value.id)
    await loadValues()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.lookupValues.deleteFailed')
  }
}

async function loadValues(): Promise<void> {
  const result = await lookupValuesService.list({ page: 1, per_page: 200, filter: { lookup_category_id: String(categoryId) } })
  values.value = result.data
}

async function load() {
  loading.value = true
  loadError.value = null

  try {
    ;[category.value, languages.value] = await Promise.all([lookupCategoriesService.get(categoryId), languagesService.listAll()]);
    await loadValues()
  } catch (error) {
    loadError.value = error instanceof ApiRequestError ? error.message : t('admin.lookupValues.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-2">
      <BaseButton variant="outline" size="sm" to="/admin/lookup-categories">{{ t('admin.lookupValues.backToCategories') }}</BaseButton>
    </div>

    <BaseSpinner v-if="loading" class="mx-auto" />
    <BaseAlert v-else-if="loadError" variant="danger">{{ loadError }}</BaseAlert>

    <template v-else-if="category">
      <div class="mb-6 flex items-center justify-between">
        <div>
          <h1 class="text-xl font-semibold text-neutral-900">{{ category.code }} — {{ category.name }}</h1>
          <p class="mt-1 text-sm text-neutral-500">{{ category.description || t('admin.lookupValues.pageSubtitle') }}</p>
        </div>
        <BaseButton @click="openCreate">{{ t('admin.lookupValues.addValue') }}</BaseButton>
      </div>

      <BaseAlert v-if="deleteError" variant="danger" class="mb-4">{{ deleteError }}</BaseAlert>

      <DataTable :columns="columns" :rows="values" row-key="id" :empty-message="t('admin.lookupValues.emptyMessage')">
        <template v-for="language in languages" :key="language.code" #[`cell-lang_${language.code}`]="{ row }">
          {{ row.translations?.[language.code]?.name || '—' }}
        </template>
        <template #cell-is_active="{ row }">
          <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
            {{ row.is_active ? t('admin.lookupValues.statusActive') : t('admin.lookupValues.statusInactive') }}
          </BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="openEdit(row)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
              {{ t('admin.lookupValues.delete') }}
            </button>
          </div>
        </template>
      </DataTable>

      <LookupValueFormModal v-model="modalOpen" :category="category" :value="editingValue" @saved="loadValues" />
    </template>
  </div>
</template>

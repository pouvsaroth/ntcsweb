<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import FormTemplateFormModal from '@/components/admin/FormTemplateFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formTemplatesService, type FormTemplate } from '@/services/formTemplates'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<FormTemplate>((query) =>
  formTemplatesService.list(query),
  { perPage: 25 },
)

const columns = computed(() => [
  { key: 'code', label: t('admin.formTemplates.columnCode') },
  { key: 'name', label: t('admin.formTemplates.columnName') },
  { key: 'category', label: t('admin.formTemplates.columnCategory') },
  { key: 'is_active', label: t('admin.formTemplates.columnStatus') },
  { key: 'actions', label: t('admin.formTemplates.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editingTemplate = ref<FormTemplate | null>(null)

function openCreate() {
  editingTemplate.value = null
  modalOpen.value = true
}

function openEdit(template: FormTemplate) {
  editingTemplate.value = template
  modalOpen.value = true
}

async function remove(template: FormTemplate) {
  if (!window.confirm(t('admin.formTemplates.deleteConfirm'))) return
  await formTemplatesService.remove(template.id)
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.formTemplates.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.formTemplates.subtitle') }}</p>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.formTemplates.addTemplate') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :empty-message="t('admin.formTemplates.emptyMessage')"
    >
      <template #cell-category="{ row }">{{ row.category ?? '—' }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.formTemplates.statusActive') : t('admin.formTemplates.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.formTemplates.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <FormTemplateFormModal v-model="modalOpen" :template="editingTemplate" @saved="fetch" />
  </div>
</template>

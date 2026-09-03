<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BookFormModal from '@/components/admin/BookFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { booksService, type Book } from '@/services/books'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Book>((query) =>
  booksService.list(query),
)

const columns = [
  { key: 'title', label: t('admin.books.columnTitle') },
  { key: 'author', label: t('admin.books.columnAuthor') },
  { key: 'academic_program', label: t('admin.books.columnProgram') },
  { key: 'category', label: t('admin.books.columnCategory') },
  { key: 'status', label: t('admin.books.columnStatus') },
  { key: 'actions', label: t('admin.books.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingBook = ref<Book | null>(null)

function openCreate() {
  editingBook.value = null
  modalOpen.value = true
}

function openEdit(book: Book) {
  editingBook.value = book
  modalOpen.value = true
}

async function remove(book: Book) {
  if (!window.confirm(t('admin.books.deleteConfirm'))) return
  await booksService.remove(book.id)
  await fetch()
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.books.title') }}</h1>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.books.addBook') }}</BaseButton>
    </div>

    <div class="mb-4">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.books.emptyMessage')">
      <template #cell-author="{ row }">{{ row.author || '—' }}</template>
      <template #cell-academic_program="{ row }">{{ row.academic_program?.name ?? '—' }}</template>
      <template #cell-category="{ row }">{{ row.book_category?.name ?? '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
          {{ row.status === 'active' ? t('admin.books.statusActive') : t('admin.books.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.books.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BookFormModal v-model="modalOpen" :book="editingBook" @saved="fetch" />
  </div>
</template>

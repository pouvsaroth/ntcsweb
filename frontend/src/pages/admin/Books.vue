<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BookFormModal from '@/components/admin/BookFormModal.vue'
import ProgramsTabs from '@/components/admin/ProgramsTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { booksService, type Book } from '@/services/books'
import { gradeLevelsService, type GradeLevel } from '@/services/schoolSetup'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<Book>((query) =>
  booksService.list(query),
)

const columns = [
  { key: 'title', label: t('admin.books.columnTitle') },
  { key: 'author', label: t('admin.books.columnAuthor') },
  { key: 'academic_program', label: t('admin.books.columnProgram') },
  { key: 'category', label: t('admin.books.columnCategory') },
  { key: 'grades', label: t('schoolSetup.books.gradeLevels') },
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

// As subjects by grade level (School setup > Curriculum): filter and show them.
const gradeLevels = ref<GradeLevel[]>([])
const gradeFilter = ref('')
const gradeOptions = computed(() => [
  { value: '', label: t('schoolSetup.books.allGrades') },
  ...gradeLevels.value.map((g) => ({ value: String(g.id), label: g.name })),
])
function onGrade(value: string) {
  gradeFilter.value = value
  setFilter('grade_level_id', value || undefined)
}
const gradesOf = (book: Book) => (book.grade_levels ?? []).map((g) => g.name).join(', ')

onMounted(async () => {
  void fetch()
  gradeLevels.value = await gradeLevelsService.list(true).catch(() => [])
})
</script>

<template>
  <div>
    <ProgramsTabs />

    <div class="mb-6 flex flex-wrap items-center gap-2">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect v-if="gradeLevels.length" class="w-44" :model-value="gradeFilter" :options="gradeOptions" @update:model-value="onGrade" />
      <BaseButton class="ml-auto" @click="openCreate">{{ t('admin.books.addBook') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.books.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ row.title }}</p>
            <BaseBadge :variant="row.status === 'active' ? 'success' : 'neutral'">
              {{ row.status === 'active' ? t('admin.books.statusActive') : t('admin.books.statusInactive') }}
            </BaseBadge>
          </div>
          <p class="text-xs text-neutral-500">{{ [row.academic_program?.name, row.book_category?.name, row.author].filter(Boolean).join(' · ') || '—' }}</p>
          <p v-if="gradesOf(row)" class="text-xs text-neutral-500">{{ t('schoolSetup.books.gradeLevels') }}: {{ gradesOf(row) }}</p>
          <div class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="openEdit(row)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.books.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.books.emptyMessage')">
      <template #cell-author="{ row }">{{ row.author || '—' }}</template>
      <template #cell-academic_program="{ row }">{{ row.academic_program?.name ?? '—' }}</template>
      <template #cell-category="{ row }">{{ row.book_category?.name ?? '—' }}</template>
      <template #cell-grades="{ row }">
        <span v-if="!row.grade_levels?.length" class="text-neutral-400">—</span>
        <span v-for="g in row.grade_levels" :key="g.id" class="mr-1 inline-block rounded-full bg-secondary-50 px-2 py-0.5 text-xs text-secondary-800">{{ g.name }}</span>
      </template>
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

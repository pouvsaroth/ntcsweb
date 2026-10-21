<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { academicYearsService, type AcademicYear } from '@/services/academicYears'
import { classesService, type SchoolClass } from '@/services/classes'
import { lookupsService } from '@/services/lookups'
import { studentsService, type Student, type StudentStatus } from '@/services/students'
import { useAdminUiStore } from '@/stores/adminUi'
import { genderLabel as builtInGenderLabel } from '@/utils/gender'

const { t, locale } = useI18n()
const adminUi = useAdminUiStore()

/** code -> translated label, e.g. "male" -> "Male" / "ប្រុស" — same GENDER category LookupSelect uses on the form. */
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

const previewStudent = ref<Student | null>(null)

function openPhotoPreview(row: Student) {
  if (!row.photo_url) return
  previewStudent.value = row
}

const { items, meta, loading, error, perPage, sort, setPage, setSearch, setSort, setFilter, fetch } = usePaginatedResource<Student>((query) =>
  studentsService.list(query),
)

const perPageOptions = [10, 25, 50, 100]

const columns = [
  { key: 'photo_url', label: t('admin.students.columnPhoto') },
  { key: 'full_name', label: t('admin.students.columnName'), sortable: true, sortKey: 'first_name' },
  { key: 'student_code', label: t('admin.students.columnCode'), sortable: true },
  { key: 'phone', label: t('admin.students.columnPhone') },
  { key: 'gender', label: t('admin.students.columnGender') },
  { key: 'address', label: t('admin.students.columnAddress') },
  { key: 'guardians', label: t('admin.students.columnGuardians') },
  { key: 'status', label: t('admin.students.columnStatus') },
  { key: 'actions', label: t('admin.students.columnActions'), align: 'text-right' },
]

const statusBadgeVariant: Record<StudentStatus, 'success' | 'neutral' | 'danger' | 'warning'> = {
  active: 'success',
  graduated: 'neutral',
  withdrawn: 'danger',
  inactive: 'warning',
  transferred: 'warning',
}

// Student search: also by status, academic year and class (docs/academics/student-record.md).
const years = ref<AcademicYear[]>([])
const classes = ref<SchoolClass[]>([])
const filters = ref({ status: '', academic_year_id: '', class_id: '' })
const statusOptions = computed(() => [
  { value: '', label: t('studentRecord.allStatuses') },
  ...(['active', 'transferred', 'withdrawn', 'graduated', 'inactive'] as StudentStatus[]).map((s) => ({ value: s, label: statusLabel(s) })),
])
const yearOptions = computed(() => [{ value: '', label: t('studentRecord.allYears') }, ...years.value.map((y) => ({ value: String(y.id), label: y.name }))])
const classOptions = computed(() => [
  { value: '', label: t('studentRecord.allClasses') },
  ...classes.value
    .filter((c) => !filters.value.academic_year_id || String(c.academic_year_id ?? '') === filters.value.academic_year_id)
    .map((c) => ({ value: String(c.id), label: c.name })),
])
function onFilter(key: 'status' | 'academic_year_id' | 'class_id', value: string) {
  filters.value[key] = value
  if (key === 'academic_year_id' && filters.value.class_id && !classOptions.value.some((o) => o.value === filters.value.class_id)) {
    filters.value.class_id = ''
    setFilter('class_id', undefined)
  }
  setFilter(key, value || undefined)
}

function statusLabel(status: StudentStatus): string {
  return t(`admin.students.status${status.charAt(0).toUpperCase()}${status.slice(1)}`)
}

onMounted(() => {
  fetch()
  loadGenderLabels()
  academicYearsService.listAll().then((r) => (years.value = r)).catch(() => undefined)
  classesService.list({ page: 1, per_page: 200, sort: 'name', filter: {} }).then((r) => (classes.value = r.data)).catch(() => undefined)
})
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-center gap-2">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-40" :model-value="filters.status" :options="statusOptions" @update:model-value="onFilter('status', $event)" />
      <BaseSelect v-if="years.length" class="w-40" :model-value="filters.academic_year_id" :options="yearOptions" @update:model-value="onFilter('academic_year_id', $event)" />
      <BaseSelect v-if="classes.length" class="w-48" :model-value="filters.class_id" :options="classOptions" @update:model-value="onFilter('class_id', $event)" />
      <BaseButton class="ml-auto" to="/admin/students/new">{{ t('admin.students.registerTitle') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on small screens — a table's columns don't have room to breathe
         on a phone; below sm: this replaces the DataTable entirely. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.students.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="flex items-center gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <button
            type="button"
            class="h-10 w-10 shrink-0 overflow-hidden rounded-full bg-neutral-100"
            :class="row.photo_url ? 'cursor-pointer' : 'cursor-default'"
            @click="openPhotoPreview(row)"
          >
            <img v-if="row.photo_url" :src="row.photo_url" alt="" class="h-full w-full object-cover" />
          </button>
          <div class="min-w-0 flex-1">
            <RouterLink :to="`/admin/students/${row.id}`" class="block truncate text-sm font-medium text-primary-700 hover:underline">{{ row.full_name }}</RouterLink>
            <p class="truncate text-xs text-neutral-500">{{ row.phone || '—' }}</p>
          </div>
          <EditIconButton :to="`/admin/students/${row.id}/edit`" />
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable
        :columns="columns"
        :rows="items"
        row-key="id"
        :loading="loading"
        :sort="sort"
        :empty-message="t('admin.students.emptyMessage')"
        @sort="(col) => setSort(sort === col ? `-${col}` : col)"
      >
        <template #cell-photo_url="{ row }">
          <button
            type="button"
            class="h-10 w-10 overflow-hidden rounded-full bg-neutral-100"
            :class="row.photo_url ? 'cursor-pointer' : 'cursor-default'"
            @click="openPhotoPreview(row)"
          >
            <img v-if="row.photo_url" :src="row.photo_url" alt="" class="h-full w-full object-cover" />
          </button>
        </template>
        <template #cell-full_name="{ row }">
          <RouterLink :to="`/admin/students/${row.id}`" class="font-medium text-primary-700 hover:underline">{{ row.full_name }}</RouterLink>
          <p v-if="row.english_name" class="text-xs text-neutral-500">{{ row.english_name }}</p>
        </template>
        <template #cell-gender="{ row }">
          {{ genderLabel(row.gender) }}
        </template>
        <template #cell-address="{ row }">
          <span class="text-neutral-600">{{ row.address ?? '—' }}</span>
        </template>
        <template #cell-guardians="{ row }">
          {{ row.guardians_count ?? 0 }}
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusBadgeVariant[row.status as StudentStatus]">
            {{ statusLabel(row.status) }}
          </BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end">
            <EditIconButton :to="`/admin/students/${row.id}/edit`" />
          </div>
        </template>
      </DataTable>
    </div>

    <!-- The per-page selector and pager stick together as one bar — sticky
         only on the BasePagination inside would leave this selector behind
         when the pager pins to the bottom. `fixed`, not `sticky` — see
         BasePagination's `sticky` prop doc for why. -->
    <div
      v-if="meta"
      class="fixed inset-x-0 bottom-0 z-10 mt-4 flex flex-col items-center gap-3 border-t border-neutral-200 bg-white/95 px-4 py-3 backdrop-blur sm:flex-row sm:justify-between sm:px-6"
      :class="adminUi.sidebarCollapsed ? 'lg:left-16' : 'lg:left-64'"
    >
      <label class="flex items-center gap-2 text-sm text-neutral-500">
        {{ t('admin.students.perPage') }}
        <select
          v-model.number="perPage"
          class="rounded-lg border border-neutral-300 py-1.5 pl-2 pr-7 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        >
          <option v-for="option in perPageOptions" :key="option" :value="option">{{ option }}</option>
        </select>
      </label>

      <BasePagination :meta="meta" @update:page="setPage" />
    </div>

    <BaseModal :model-value="previewStudent !== null" :title="previewStudent?.full_name" @update:model-value="previewStudent = null">
      <img v-if="previewStudent?.photo_url" :src="previewStudent.photo_url" alt="" class="mx-auto max-h-[70vh] w-auto rounded-lg object-contain" />
    </BaseModal>
  </div>
</template>

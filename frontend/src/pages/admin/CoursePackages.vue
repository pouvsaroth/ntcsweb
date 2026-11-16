<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CoursePackageFormModal from '@/components/admin/CoursePackageFormModal.vue'
import ProgramsTabs from '@/components/admin/ProgramsTabs.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { coursePackagesService, type CoursePackage } from '@/services/coursePackages'
import { ApiRequestError } from '@/types/api'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { moneyOrDash } from '@/utils/currency'

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<CoursePackage>((query) =>
  coursePackagesService.list(query),
)

const columns = [
  { key: 'sort_order', label: t('admin.coursePackages.columnSortOrder') },
  { key: 'code', label: t('admin.coursePackages.columnCode') },
  { key: 'name', label: t('admin.coursePackages.columnName') },
  { key: 'program', label: t('admin.coursePackages.columnProgram') },
  { key: 'books', label: t('admin.coursePackages.columnBooks') },
  { key: 'fee_monthly', label: t('admin.coursePackages.columnFeeMonthly') },
  { key: 'fee_term', label: t('admin.coursePackages.columnFeeTerm') },
  { key: 'fee_video', label: t('admin.coursePackages.columnFeeVideo') },
  { key: 'fee_monthly_online', label: t('admin.coursePackages.columnFeeMonthlyOnline') },
  { key: 'fee_term_online', label: t('admin.coursePackages.columnFeeTermOnline') },
  { key: 'currency', label: t('admin.coursePackages.columnCurrency') },
  { key: 'is_active', label: t('admin.coursePackages.columnStatus') },
  { key: 'show_on_website', label: t('admin.coursePackages.columnShowOnWebsite') },
  { key: 'show_in_popular', label: t('admin.coursePackages.columnShowInPopular') },
  { key: 'actions', label: t('admin.coursePackages.columnActions'), align: 'text-right' },
]

const modalOpen = ref(false)
const editingPackage = ref<CoursePackage | null>(null)
const deleteError = ref<string | null>(null)

function openCreate() {
  editingPackage.value = null
  modalOpen.value = true
}

function openEdit(coursePackage: CoursePackage) {
  editingPackage.value = coursePackage
  modalOpen.value = true
}

async function remove(coursePackage: CoursePackage) {
  if (!(await confirmDialog.confirm({ message: t('admin.coursePackages.deleteConfirm'), danger: true }))) return
  deleteError.value = null

  try {
    await coursePackagesService.remove(coursePackage.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.coursePackages.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <ProgramsTabs />

    <div class="mb-6 flex items-center justify-between">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="auth.can('course-packages.create')" @click="openCreate">{{ t('admin.coursePackages.addPackage') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <!-- Cards on small screens (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('admin.coursePackages.emptyMessage') }}</p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.name }}</p>
              <p class="text-xs text-neutral-500">{{ row.code }}<template v-if="row.academic_program"> · {{ row.academic_program.code }}</template></p>
            </div>
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.coursePackages.statusActive') : t('admin.coursePackages.statusInactive') }}</BaseBadge>
          </div>
          <dl class="mt-2 space-y-0.5 text-xs">
              <div v-if="row.fee_monthly !== null" class="flex justify-between gap-2"><dt class="text-neutral-500">{{ t('admin.coursePackages.columnFeeMonthly') }}</dt><dd class="text-right tabular-nums">{{ moneyOrDash(row.fee_monthly_usd, 'USD') }} / {{ moneyOrDash(row.fee_monthly_khr, 'KHR') }}</dd></div>
              <div v-if="row.fee_term !== null" class="flex justify-between gap-2"><dt class="text-neutral-500">{{ t('admin.coursePackages.columnFeeTerm') }}</dt><dd class="text-right tabular-nums">{{ moneyOrDash(row.fee_term_usd, 'USD') }} / {{ moneyOrDash(row.fee_term_khr, 'KHR') }}</dd></div>
              <div v-if="row.fee_video !== null" class="flex justify-between gap-2"><dt class="text-neutral-500">{{ t('admin.coursePackages.columnFeeVideo') }}</dt><dd class="text-right tabular-nums">{{ moneyOrDash(row.fee_video_usd, 'USD') }} / {{ moneyOrDash(row.fee_video_khr, 'KHR') }}</dd></div>
              <div v-if="row.fee_monthly_online !== null" class="flex justify-between gap-2"><dt class="text-neutral-500">{{ t('admin.coursePackages.columnFeeMonthlyOnline') }}</dt><dd class="text-right tabular-nums">{{ moneyOrDash(row.fee_monthly_online_usd, 'USD') }} / {{ moneyOrDash(row.fee_monthly_online_khr, 'KHR') }}</dd></div>
              <div v-if="row.fee_term_online !== null" class="flex justify-between gap-2"><dt class="text-neutral-500">{{ t('admin.coursePackages.columnFeeTermOnline') }}</dt><dd class="text-right tabular-nums">{{ moneyOrDash(row.fee_term_online_usd, 'USD') }} / {{ moneyOrDash(row.fee_term_online_khr, 'KHR') }}</dd></div>
          </dl>
          <div class="mt-2 flex items-center justify-end gap-2">
            <EditIconButton v-if="auth.can('course-packages.update')" @click="openEdit(row)" />
            <button v-if="auth.can('course-packages.delete')" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.coursePackages.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.coursePackages.emptyMessage')">
      <template #cell-program="{ row }">{{ row.academic_program?.code ?? '—' }}</template>
      <template #cell-books="{ row }">{{ row.books?.map((b) => b.title).join(', ') || '—' }}</template>
      <template #cell-fee_monthly="{ row }"><span class="whitespace-nowrap tabular-nums"><template v-if="row.fee_monthly !== null">{{ moneyOrDash(row.fee_monthly_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(row.fee_monthly_khr, 'KHR') }}</span></template><template v-else>—</template></span></template>
      <template #cell-fee_term="{ row }"><span class="whitespace-nowrap tabular-nums"><template v-if="row.fee_term !== null">{{ moneyOrDash(row.fee_term_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(row.fee_term_khr, 'KHR') }}</span></template><template v-else>—</template></span></template>
      <template #cell-fee_video="{ row }"><span class="whitespace-nowrap tabular-nums"><template v-if="row.fee_video !== null">{{ moneyOrDash(row.fee_video_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(row.fee_video_khr, 'KHR') }}</span></template><template v-else>—</template></span></template>
      <template #cell-fee_monthly_online="{ row }"><span class="whitespace-nowrap tabular-nums"><template v-if="row.fee_monthly_online !== null">{{ moneyOrDash(row.fee_monthly_online_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(row.fee_monthly_online_khr, 'KHR') }}</span></template><template v-else>—</template></span></template>
      <template #cell-fee_term_online="{ row }"><span class="whitespace-nowrap tabular-nums"><template v-if="row.fee_term_online !== null">{{ moneyOrDash(row.fee_term_online_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(row.fee_term_online_khr, 'KHR') }}</span></template><template v-else>—</template></span></template>
      <template #cell-currency="{ row }">{{ row.currency }}</template>
      <template #cell-is_active="{ row }">
        <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">
          {{ row.is_active ? t('admin.coursePackages.statusActive') : t('admin.coursePackages.statusInactive') }}
        </BaseBadge>
      </template>
      <template #cell-show_on_website="{ row }">
        <BaseBadge :variant="row.show_on_website ? 'success' : 'neutral'">
          {{ row.show_on_website ? t('admin.coursePackages.shown') : t('admin.coursePackages.hidden') }}
        </BaseBadge>
      </template>
      <template #cell-show_in_popular="{ row }">
        <BaseBadge :variant="row.show_in_popular ? 'success' : 'neutral'">
          {{ row.show_in_popular ? t('admin.coursePackages.shown') : t('admin.coursePackages.hidden') }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <EditIconButton v-if="auth.can('course-packages.update')" @click="openEdit(row)" />
          <button v-if="auth.can('course-packages.delete')" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.coursePackages.delete') }}
          </button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CoursePackageFormModal v-model="modalOpen" :course-package="editingPackage" @saved="fetch" />
  </div>
</template>

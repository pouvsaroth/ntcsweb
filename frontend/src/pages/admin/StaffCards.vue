<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import StaffCardCanvas from '@/components/admin/StaffCardCanvas.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useCardKind } from '@/composables/useCardKind'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { staffCardDesignsService, type StaffCardDesign } from '@/services/staffCardDesigns'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import type { CardField, CardValues } from '@/utils/staffCard'

/** HRM > Staff cards / Students > Student cards: the school's card designs, each shown with its front and back. */
const { t } = useI18n()
const { cardFor, isStudent, base, permission, fieldLabel, sample } = useCardKind()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<StaffCardDesign>((query) => staffCardDesignsService.list(query, cardFor.value))

const canCreate = computed(() => auth.can(permission('create')))
const canUpdate = computed(() => auth.can(permission('update')))
const canDelete = computed(() => auth.can(permission('delete')))

// Thumbnails show made-up details instead of a real person's.
const labels = computed<CardValues>(() => ({ ...sample.value, expiry_date: '31-12-2027', qr: isStudent.value ? 'STU0001' : 'ST0001' }))
const placeholder = (field: CardField) => fieldLabel(field.type)
const thumbScale = (design: StaffCardDesign) => Math.min(150 / design.width_mm, 150 / design.height_mm)
const thumbValues = (design: StaffCardDesign): CardValues => ({ ...labels.value, logo: design.school_logo_url, signature: design.signature_url })

let searchTimer: ReturnType<typeof setTimeout> | undefined
function onSearch(value: string) {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => setSearch(value), 300)
}

const deleteError = ref<string | null>(null)
async function remove(design: StaffCardDesign) {
  if (!(await confirmDialog.confirm({ message: t('admin.staffCards.deleteConfirm', { name: design.name }), danger: true }))) return
  deleteError.value = null
  try {
    await staffCardDesignsService.remove(design.id)
    await fetch()
  } catch (e) {
    deleteError.value = e instanceof ApiRequestError ? e.message : t('admin.staffCards.deleteFailed')
  }
}

onMounted(() => void fetch())
</script>

<template>
  <div>
    <p class="mb-4 text-sm text-neutral-500">{{ isStudent ? t('admin.staffCards.studentPageSubtitle') : t('admin.staffCards.pageSubtitle') }}</p>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <input
        type="search"
        :placeholder="t('admin.staffCards.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-sm"
        @input="onSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="canCreate" :to="`${base}/new`">{{ t('admin.staffCards.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || deleteError" variant="danger" class="mb-4">{{ error || deleteError }}</BaseAlert>

    <div v-if="loading && !items.length" class="flex justify-center py-16"><BaseSpinner /></div>
    <div v-else-if="!items.length" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-12 text-center text-sm text-neutral-500">
      {{ t('admin.staffCards.empty') }}
    </div>
    <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <div v-for="design in items" :key="design.id" class="flex flex-col rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="flex flex-wrap items-start justify-center gap-3 rounded-lg bg-neutral-100 p-3">
          <StaffCardCanvas
            v-for="side in (['front', 'back'] as const).filter((s) => design[`has_${s}`])"
            :key="side"
            :width-mm="design.width_mm"
            :height-mm="design.height_mm"
            :background="design[`${side}_background_url`]"
            :fields="design[`${side}_fields`]"
            :values="thumbValues(design)"
            :scale="thumbScale(design) * 0.85"
            :placeholder="placeholder"
          />
        </div>
        <div class="mt-3 flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="truncate font-medium text-neutral-900">{{ design.name }}</p>
            <p class="text-xs text-neutral-500">
              {{ t('admin.staffCards.sizeLine', { w: design.width_mm, h: design.height_mm }) }}
              · {{ t('admin.staffCards.fieldCount', { n: design.front_fields.length + design.back_fields.length }) }}
            </p>
            <p v-if="design.updated_at" class="text-xs text-neutral-400">{{ t('admin.staffCards.updatedOn', { date: formatDate(design.updated_at) }) }}</p>
          </div>
          <div class="flex shrink-0 items-center gap-3">
            <RouterLink :to="`${base}/${design.id}/print`" class="text-sm font-medium text-primary-700 hover:underline">{{ t('admin.staffCards.printCards') }}</RouterLink>
            <RouterLink v-if="canUpdate" :to="`${base}/${design.id}/edit`" class="text-sm font-medium text-primary-700 hover:underline">{{ t('admin.staffCards.design') }}</RouterLink>
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(design)">{{ t('admin.staffCards.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />
  </div>
</template>

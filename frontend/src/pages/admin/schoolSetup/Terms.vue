<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { academicYearsService, type AcademicYear } from '@/services/academicYears'
import { academicTermsService, type AcademicTerm } from '@/services/schoolSetup'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/** School setup > Terms / semesters: the terms of each academic year, with their dates. */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('academic-terms.manage'))

const years = ref<AcademicYear[]>([])
const yearId = ref<string>('')
const terms = ref<AcademicTerm[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const yearOptions = computed(() => [
  { value: '', label: t('schoolSetup.allYears') },
  ...years.value.map((y) => ({ value: String(y.id), label: y.is_current ? `${y.name} (${t('schoolSetup.current')})` : y.name })),
])
const formYearOptions = computed(() => years.value.map((y) => ({ value: String(y.id), label: y.name })))

async function load() {
  loading.value = true
  error.value = null
  try {
    terms.value = await academicTermsService.list(yearId.value ? Number(yearId.value) : null)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.loadFailed')
  } finally {
    loading.value = false
  }
}

function onYear(value: string) {
  yearId.value = value
  void load()
}

const range = (term: AcademicTerm) => (term.start_date || term.end_date ? `${formatDate(term.start_date)} – ${formatDate(term.end_date)}` : '—')

const columns = computed(() => [
  { key: 'name', label: t('schoolSetup.terms.name') },
  { key: 'year', label: t('schoolSetup.academicYear') },
  { key: 'dates', label: t('schoolSetup.dates') },
  ...(canManage.value ? [{ key: 'actions', label: t('schoolSetup.actions'), align: 'text-right' }] : []),
])

// --- Form ----------------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<AcademicTerm | null>(null)
const form = reactive({ academic_year_id: '', name: '', start_date: '', end_date: '', sort_order: '0' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

function open(term: AcademicTerm | null) {
  editing.value = term
  const current = years.value.find((y) => y.is_current)
  form.academic_year_id = term ? String(term.academic_year_id) : yearId.value || (current ? String(current.id) : '')
  form.name = term?.name ?? ''
  form.start_date = term?.start_date ?? ''
  form.end_date = term?.end_date ?? ''
  form.sort_order = String(term?.sort_order ?? terms.value.length + 1)
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    academic_year_id: Number(form.academic_year_id),
    name: form.name.trim(),
    start_date: form.start_date || null,
    end_date: form.end_date || null,
    sort_order: Number(form.sort_order) || 0,
  }
  try {
    if (editing.value) await academicTermsService.update(editing.value.id, input)
    else await academicTermsService.create(input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(term: AcademicTerm) {
  if (!(await confirmDialog.confirm({ message: t('schoolSetup.terms.deleteConfirm', { name: term.name }), danger: true }))) return
  try {
    await academicTermsService.remove(term.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('schoolSetup.deleteFailed')
  }
}

onMounted(async () => {
  years.value = await academicYearsService.listAll().catch(() => [])
  const current = years.value.find((y) => y.is_current)
  if (current) yearId.value = String(current.id)
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-56" :model-value="yearId" :options="yearOptions" @update:model-value="onYear" />
      <RouterLink to="/admin/academic-years" class="text-sm text-secondary-700 hover:underline">{{ t('schoolSetup.terms.manageYears') }}</RouterLink>
      <BaseButton v-if="canManage" class="ml-auto" :disabled="years.length === 0" @click="open(null)">{{ t('schoolSetup.terms.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="!loading && years.length === 0" variant="info" class="mb-4">{{ t('schoolSetup.terms.noYears') }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="terms.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('schoolSetup.terms.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in terms" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ row.name }}</p>
            <span class="text-xs text-neutral-500">{{ row.academic_year }}</span>
          </div>
          <p class="text-xs text-neutral-500">{{ range(row) }}</p>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="open(row)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('schoolSetup.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="terms" row-key="id" :loading="loading" :empty-message="t('schoolSetup.terms.empty')">
        <template #cell-name="{ row }"><span class="font-medium text-neutral-800">{{ row.name }}</span></template>
        <template #cell-year="{ row }">{{ row.academic_year }}</template>
        <template #cell-dates="{ row }">{{ range(row as AcademicTerm) }}</template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="open(row as AcademicTerm)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as AcademicTerm)">{{ t('schoolSetup.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BaseModal v-model="formOpen" :title="editing ? t('schoolSetup.terms.edit') : t('schoolSetup.terms.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <BaseSelect
          v-model="form.academic_year_id"
          :options="formYearOptions"
          :placeholder="t('schoolSetup.choose')"
          required
          :label="t('schoolSetup.academicYear')"
          :error="errors.academic_year_id?.[0]"
        />
        <BaseInput v-model="form.name" required :label="t('schoolSetup.terms.name')" :hint="t('schoolSetup.terms.nameHint')" :error="errors.name?.[0]" />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.start_date" type="date" :label="t('schoolSetup.startDate')" :error="errors.start_date?.[0]" />
          <BaseInput v-model="form.end_date" type="date" :label="t('schoolSetup.endDate')" :error="errors.end_date?.[0]" />
        </div>
        <BaseInput v-model="form.sort_order" type="number" :label="t('schoolSetup.order')" :error="errors.sort_order?.[0]" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

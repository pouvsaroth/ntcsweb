<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { EDUCATION_LEVELS, staffEducationService, type EducationLevel, type StaffEducation } from '@/services/staffEducation'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Staff > Education (docs/hrm/staff-education.md): the staff member's
 * schools and universities, as many as they have. Changing needs staff.update.
 * A new staff member must be saved first (the rows hang off the saved record).
 */
const props = defineProps<{ staffId: number | null }>()

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('staff.update'))

const rows = ref<StaffEducation[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  if (props.staffId === null) return
  loading.value = true
  error.value = null
  try {
    rows.value = await staffEducationService.list(props.staffId)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.staff.education.loadFailed')
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => props.staffId, load)

const levelOptions = computed(() => EDUCATION_LEVELS.map((v) => ({ value: v, label: t(`admin.staff.education.levels.${v}`) })))
function years(e: StaffEducation): string {
  if (e.start_year === null && e.end_year === null) return '—'
  return `${e.start_year ?? '…'} – ${e.end_year ?? t('admin.staff.education.studying')}`
}

// --- Add / edit ---------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<StaffEducation | null>(null)
const form = reactive({ level: 'bachelor' as EducationLevel, institution: '', major: '', country: '', start_year: '', end_year: '', note: '' })
const errors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)
const saving = ref(false)

function open(e: StaffEducation | null): void {
  editing.value = e
  form.level = e?.level ?? 'bachelor'
  form.institution = e?.institution ?? ''
  form.major = e?.major ?? ''
  form.country = e?.country ?? ''
  form.start_year = e?.start_year ? String(e.start_year) : ''
  form.end_year = e?.end_year ? String(e.end_year) : ''
  form.note = e?.note ?? ''
  errors.value = {}
  formError.value = null
  formOpen.value = true
}

const yearOrNull = (v: string) => (String(v).trim() === '' ? null : Number(v))

async function save(): Promise<void> {
  if (props.staffId === null) return
  saving.value = true
  errors.value = {}
  formError.value = null
  const input = {
    level: form.level,
    institution: form.institution.trim(),
    major: form.major.trim() || null,
    country: form.country.trim() || null,
    start_year: yearOrNull(form.start_year),
    end_year: yearOrNull(form.end_year),
    note: form.note.trim() || null,
  }
  try {
    if (editing.value) await staffEducationService.update(props.staffId, editing.value.id, input)
    else await staffEducationService.create(props.staffId, input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else formError.value = e instanceof ApiRequestError ? e.message : t('admin.staff.education.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(e: StaffEducation): Promise<void> {
  if (props.staffId === null) return
  if (!(await confirmDialog.confirm({ message: t('admin.staff.education.deleteConfirm', { name: e.institution }), danger: true }))) return
  try {
    await staffEducationService.remove(props.staffId, e.id)
    await load()
  } catch (err) {
    error.value = err instanceof ApiRequestError ? err.message : t('admin.staff.education.saveFailed')
  }
}

const err = (k: string) => errors.value[k]?.[0]
</script>

<template>
  <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
      <div>
        <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.staff.tabEducation') }}</h2>
        <p class="text-xs text-neutral-500">{{ t('admin.staff.education.hint') }}</p>
      </div>
      <BaseButton v-if="canManage && staffId !== null" size="sm" @click="open(null)">{{ t('admin.staff.education.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="staffId === null" variant="info">{{ t('admin.staff.education.saveFirst') }}</BaseAlert>
    <template v-else>
      <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
      <div v-if="loading" class="flex justify-center py-8"><BaseSpinner /></div>
      <p v-else-if="rows.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.staff.education.empty') }}</p>

      <template v-else>
        <!-- Desktop: a table -->
        <div class="hidden overflow-x-auto sm:block">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-50 text-xs font-semibold tracking-wide text-neutral-500 uppercase">
              <tr>
                <th class="px-3 py-2">{{ t('admin.staff.education.level') }}</th>
                <th class="px-3 py-2">{{ t('admin.staff.education.institution') }}</th>
                <th class="px-3 py-2">{{ t('admin.staff.education.major') }}</th>
                <th class="px-3 py-2">{{ t('admin.staff.education.country') }}</th>
                <th class="px-3 py-2">{{ t('admin.staff.education.years') }}</th>
                <th v-if="canManage" class="px-3 py-2 text-right">{{ t('admin.staff.education.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="e in rows" :key="e.id">
                <td class="px-3 py-2.5 whitespace-nowrap text-neutral-800">{{ t(`admin.staff.education.levels.${e.level}`) }}</td>
                <td class="px-3 py-2.5">
                  <p class="font-medium text-neutral-900">{{ e.institution }}</p>
                  <p v-if="e.note" class="text-xs text-neutral-500">{{ e.note }}</p>
                </td>
                <td class="px-3 py-2.5 text-neutral-700">{{ e.major ?? '—' }}</td>
                <td class="px-3 py-2.5 text-neutral-700">{{ e.country ?? '—' }}</td>
                <td class="px-3 py-2.5 whitespace-nowrap text-neutral-700">{{ years(e) }}</td>
                <td v-if="canManage" class="px-3 py-2.5 text-right whitespace-nowrap">
                  <button type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="open(e)">{{ t('admin.staff.education.edit') }}</button>
                  <button type="button" class="ml-3 text-sm font-medium text-red-600 hover:underline" @click="remove(e)">{{ t('admin.staff.education.delete') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Phone: cards -->
        <ul class="space-y-3 sm:hidden">
          <li v-for="e in rows" :key="e.id" class="rounded-lg border border-neutral-200 p-3">
            <p class="text-xs font-medium text-primary-700">{{ t(`admin.staff.education.levels.${e.level}`) }} · {{ years(e) }}</p>
            <p class="mt-0.5 font-medium text-neutral-900">{{ e.institution }}</p>
            <p v-if="e.major || e.country" class="text-sm text-neutral-600">{{ [e.major, e.country].filter(Boolean).join(' · ') }}</p>
            <p v-if="e.note" class="mt-1 text-xs text-neutral-500">{{ e.note }}</p>
            <div v-if="canManage" class="mt-2 flex gap-4 text-sm">
              <button type="button" class="font-medium text-secondary-700 hover:underline" @click="open(e)">{{ t('admin.staff.education.edit') }}</button>
              <button type="button" class="font-medium text-red-600 hover:underline" @click="remove(e)">{{ t('admin.staff.education.delete') }}</button>
            </div>
          </li>
        </ul>
      </template>
    </template>

    <BaseModal v-model="formOpen" size="lg" :title="t(editing ? 'admin.staff.education.editTitle' : 'admin.staff.education.addTitle')">
      <form id="staff-education-form" class="space-y-4" novalidate @submit.prevent="save">
        <BaseAlert v-if="formError" variant="danger">{{ formError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.level" :options="levelOptions" :label="t('admin.staff.education.level')" :error="err('level')" required />
          <BaseInput v-model="form.institution" :label="t('admin.staff.education.institution')" :placeholder="t('admin.staff.education.institutionHint')" :error="err('institution')" required />
          <BaseInput v-model="form.major" :label="t('admin.staff.education.major')" :placeholder="t('admin.staff.education.majorHint')" :error="err('major')" />
          <BaseInput v-model="form.country" :label="t('admin.staff.education.country')" :error="err('country')" />
          <BaseInput v-model="form.start_year" type="number" :label="t('admin.staff.education.startYear')" placeholder="2015" :error="err('start_year')" />
          <BaseInput v-model="form.end_year" type="number" :label="t('admin.staff.education.endYear')" placeholder="2019" :hint="t('admin.staff.education.endYearHint')" :error="err('end_year')" />
        </div>
        <BaseInput v-model="form.note" :label="t('admin.staff.education.note')" :error="err('note')" />
      </form>
      <template #footer>
        <div class="flex w-full flex-wrap justify-end gap-2">
          <BaseButton variant="outline" @click="formOpen = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton type="submit" form="staff-education-form" :loading="saving">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>

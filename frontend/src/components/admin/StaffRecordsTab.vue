<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { staffRecordsService, type StaffRecord, type StaffRecordValue } from '@/services/staffRecords'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { STAFF_RECORD_OPTIONS, type StaffRecordField, type StaffRecordKind } from '@/utils/staffRecordKinds'

/**
 * One list-style tab of the staff form (docs/hrm/staff-profile-tabs.md):
 * Emergency Contacts, Family Members, ... drawn from its StaffRecordKind.
 * A table on desktop, cards on phone; add and edit in a form. Changing
 * needs staff.update; a new staff member must be saved first.
 */
const props = defineProps<{ staffId: number | null; config: StaffRecordKind }>()

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => auth.can('staff.update'))

const k = (key: string, params?: Record<string, unknown>) => t(`admin.staff.records.${props.config.i18n}.${key}`, params ?? {})
const label = (f: StaffRecordField) => k(`fields.${f.key}`)

const rows = ref<StaffRecord[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  if (props.staffId === null) return
  loading.value = true
  error.value = null
  try {
    rows.value = await staffRecordsService.list(props.staffId, props.config.kind)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.staff.records.loadFailed')
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => [props.staffId, props.config.kind], load)

const listFields = computed(() => props.config.fields.filter((f) => f.inList))
const cardFields = computed(() => listFields.value.filter((f) => f.key !== props.config.titleField))
const optionsFor = (f: StaffRecordField) => (f.options ? STAFF_RECORD_OPTIONS[f.options].map((v) => ({ value: v, label: t(`admin.staff.records.options.${f.options}.${v}`) })) : [])

/** A row's value as text: a choice's name, a date as dd-mm-yyyy. */
function show(row: StaffRecord, f: StaffRecordField): string {
  const v = row[f.key]
  if (v === null || v === undefined || v === '') return '—'
  if (f.type === 'select' && f.options) return t(`admin.staff.records.options.${f.options}.${v}`)
  if (f.type === 'date') return formatDate(String(v))
  return String(v)
}

// --- Add / edit -----------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<StaffRecord | null>(null)
const form = reactive<Record<string, string>>({})
const errors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)
const saving = ref(false)

function open(row: StaffRecord | null): void {
  editing.value = row
  for (const f of props.config.fields) {
    const v = row?.[f.key]
    // A new row starts on the first choice of a required select.
    form[f.key] = v === null || v === undefined ? (f.type === 'select' && f.required && f.options ? STAFF_RECORD_OPTIONS[f.options][0] : '') : String(v)
  }
  errors.value = {}
  formError.value = null
  formOpen.value = true
}

async function save(): Promise<void> {
  if (props.staffId === null) return
  saving.value = true
  errors.value = {}
  formError.value = null
  const input: Record<string, StaffRecordValue> = {}
  for (const f of props.config.fields) input[f.key] = form[f.key]?.trim() ? form[f.key].trim() : null
  try {
    if (editing.value) await staffRecordsService.update(props.staffId, props.config.kind, editing.value.id, input)
    else await staffRecordsService.create(props.staffId, props.config.kind, input)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else formError.value = e instanceof ApiRequestError ? e.message : t('admin.staff.records.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(row: StaffRecord): Promise<void> {
  if (props.staffId === null) return
  if (!(await confirmDialog.confirm({ message: t('admin.staff.records.deleteConfirm', { name: String(row[props.config.titleField] ?? '') }), danger: true }))) return
  try {
    await staffRecordsService.remove(props.staffId, props.config.kind, row.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.staff.records.saveFailed')
  }
}

const err = (key: string) => errors.value[key]?.[0]
const formId = computed(() => `staff-record-form-${props.config.kind}`)
</script>

<template>
  <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
      <div>
        <h2 class="text-sm font-semibold text-neutral-800">{{ k('title') }}</h2>
        <p class="text-xs text-neutral-500">{{ k('hint') }}</p>
      </div>
      <BaseButton v-if="canManage && staffId !== null" size="sm" @click="open(null)">{{ k('add') }}</BaseButton>
    </div>

    <BaseAlert v-if="staffId === null" variant="info">{{ t('admin.staff.records.saveFirst') }}</BaseAlert>
    <template v-else>
      <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
      <div v-if="loading" class="flex justify-center py-8"><BaseSpinner /></div>
      <p v-else-if="rows.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ k('empty') }}</p>

      <template v-else>
        <!-- Desktop: a table -->
        <div class="hidden overflow-x-auto sm:block">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-50 text-xs font-semibold tracking-wide text-neutral-500 uppercase">
              <tr>
                <th v-for="f in listFields" :key="f.key" class="px-3 py-2">{{ label(f) }}</th>
                <th v-if="canManage" class="px-3 py-2 text-right">{{ t('admin.staff.records.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in rows" :key="row.id">
                <td v-for="f in listFields" :key="f.key" class="px-3 py-2.5" :class="f.key === config.titleField ? 'font-medium text-neutral-900' : 'text-neutral-700'">
                  {{ show(row, f) }}
                  <p v-if="f.key === config.titleField && row.note" class="text-xs font-normal text-neutral-500">{{ row.note }}</p>
                </td>
                <td v-if="canManage" class="px-3 py-2.5 text-right whitespace-nowrap">
                  <button type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="open(row)">{{ t('admin.staff.records.edit') }}</button>
                  <button type="button" class="ml-3 text-sm font-medium text-red-600 hover:underline" @click="remove(row)">{{ t('admin.staff.records.delete') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Phone: cards -->
        <ul class="space-y-3 sm:hidden">
          <li v-for="row in rows" :key="row.id" class="rounded-lg border border-neutral-200 p-3">
            <p class="font-medium text-neutral-900">{{ row[config.titleField] }}</p>
            <dl class="mt-1 space-y-0.5 text-sm">
              <div v-for="f in cardFields" v-show="show(row, f) !== '—'" :key="f.key" class="flex gap-2">
                <dt class="shrink-0 text-neutral-500">{{ label(f) }}:</dt>
                <dd class="min-w-0 break-words text-neutral-800">{{ show(row, f) }}</dd>
              </div>
            </dl>
            <p v-if="row.note" class="mt-1 text-xs text-neutral-500">{{ row.note }}</p>
            <div v-if="canManage" class="mt-2 flex gap-4 text-sm">
              <button type="button" class="font-medium text-secondary-700 hover:underline" @click="open(row)">{{ t('admin.staff.records.edit') }}</button>
              <button type="button" class="font-medium text-red-600 hover:underline" @click="remove(row)">{{ t('admin.staff.records.delete') }}</button>
            </div>
          </li>
        </ul>
      </template>
    </template>

    <BaseModal v-model="formOpen" size="lg" :title="k(editing ? 'editTitle' : 'add')">
      <form :id="formId" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
        <BaseAlert v-if="formError" variant="danger" class="sm:col-span-2">{{ formError }}</BaseAlert>
        <template v-for="f in config.fields" :key="f.key">
          <BaseSelect
            v-if="f.type === 'select'"
            v-model="form[f.key]"
            :options="f.required ? optionsFor(f) : [{ value: '', label: '—' }, ...optionsFor(f)]"
            :label="label(f)"
            :required="f.required"
            :error="err(f.key)"
            :class="{ 'sm:col-span-2': f.wide }"
          />
          <BaseInput v-else v-model="form[f.key]" :type="f.type" :label="label(f)" :required="f.required" :error="err(f.key)" :class="{ 'sm:col-span-2': f.wide }" />
        </template>
      </form>
      <template #footer>
        <div class="flex w-full flex-wrap justify-end gap-2">
          <BaseButton variant="outline" @click="formOpen = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton type="submit" :form="formId" :loading="saving">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>

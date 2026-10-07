<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import {
  payAmountLabel,
  payComponentsService,
  staffPayComponentsService,
  type PayCalculation,
  type PayComponent,
  type PayComponentKind,
  type PayRecurrence,
  type StaffPayComponent,
} from '@/services/payroll'
import { staffService, type Staff } from '@/services/staff'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Payroll > Allowances / Bonuses / Deductions — one page, three tabs
 * (the route passes `kind`). "Given to staff" lists what each staff member
 * gets — recurring between two dates, or once (paid in the payroll covering
 * its date); "Types" sets up the items themselves (Transport, Year-end
 * bonus, Uniform, ...). Cards on a phone, a table from `sm` up.
 */
const props = defineProps<{ kind: PayComponentKind }>()

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canManage = computed(() => auth.can('payroll.manage'))
const view = ref<'staff' | 'types'>('staff')
const currentOnly = ref(true)
const actionError = ref<string | null>(null)

/** "allowances.add", "bonuses.add", ... — this tab's own wording. */
const k = computed(() => ({ allowance: 'allowances', bonus: 'bonuses', deduction: 'deductions' })[props.kind])

// --- Types (components) ---------------------------------------------------------------

const types = ref<PayComponent[]>([])
const typesLoading = ref(false)

async function loadTypes() {
  typesLoading.value = true
  try {
    types.value = await payComponentsService.listAll(props.kind)
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.loadFailed')
  } finally {
    typesLoading.value = false
  }
}

function traits(type: PayComponent): string {
  return [
    type.calculation === 'percent_of_basic' ? t('admin.payroll.components.percentOfBasic') : t('admin.payroll.components.fixedAmount'),
    type.affects_tax ? t(props.kind === 'deduction' ? 'admin.payroll.components.beforeTax' : 'admin.payroll.components.taxable') : null,
    type.affects_social_security ? t(props.kind === 'deduction' ? 'admin.payroll.components.beforeNssf' : 'admin.payroll.components.nssf') : null,
  ]
    .filter(Boolean)
    .join(' · ')
}

const typeFormOpen = ref(false)
const editingType = ref<PayComponent | null>(null)
const typeForm = reactive({
  code: '',
  name: '',
  calculation: 'fixed' as PayCalculation,
  affects_tax: true,
  affects_social_security: false,
  description: '',
  is_active: true,
})
const typeErrors = ref<Record<string, string[]>>({})
const typeSaveError = ref<string | null>(null)
const typeSaving = ref(false)

const calculationOptions = computed(() => [
  { value: 'fixed', label: t('admin.payroll.components.fixedAmount') },
  { value: 'percent_of_basic', label: t('admin.payroll.components.percentOfBasic') },
])

function openType(type: PayComponent | null) {
  editingType.value = type
  typeForm.code = type?.code ?? ''
  typeForm.name = type?.name ?? ''
  typeForm.calculation = type?.calculation ?? 'fixed'
  typeForm.affects_tax = type?.affects_tax ?? props.kind !== 'deduction'
  typeForm.affects_social_security = type?.affects_social_security ?? false
  typeForm.description = type?.description ?? ''
  typeForm.is_active = type?.is_active ?? true
  typeErrors.value = {}
  typeSaveError.value = null
  typeFormOpen.value = true
}

async function saveType() {
  typeSaving.value = true
  typeErrors.value = {}
  typeSaveError.value = null
  const input = { ...typeForm, description: typeForm.description.trim() || null }
  try {
    if (editingType.value) await payComponentsService.update(editingType.value.id, input)
    else await payComponentsService.create({ ...input, kind: props.kind })
    typeFormOpen.value = false
    await loadTypes()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) typeErrors.value = e.errors
    else typeSaveError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    typeSaving.value = false
  }
}

async function removeType(type: PayComponent) {
  if (!(await confirmDialog.confirm({ message: t('admin.payroll.components.deleteConfirm', { name: type.name }), danger: true }))) return
  actionError.value = null
  try {
    await payComponentsService.remove(type.id)
    await loadTypes()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

// --- Given to staff (assignments) -----------------------------------------------------------

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<StaffPayComponent>((query) =>
  staffPayComponentsService.list(query, props.kind, currentOnly.value),
)

// setPage() refetches with the new filter.
watch(currentOnly, () => setPage(1))

function amountOf(row: StaffPayComponent): string {
  return payAmountLabel(row.amount, row.component?.calculation, row.currency)
}

function periodOf(row: StaffPayComponent): string {
  if (row.recurrence === 'once') return t('admin.payroll.assignments.onceOn', { date: formatDate(row.starts_on) })
  return row.ends_on
    ? t('admin.payroll.assignments.between', { from: formatDate(row.starts_on), to: formatDate(row.ends_on) })
    : t('admin.payroll.assignments.fromOn', { date: formatDate(row.starts_on) })
}

const staff = ref<Staff[]>([])
const staffOptions = computed(() => staff.value.map((s) => ({ value: String(s.id), label: s.full_name, hint: s.employee_code })))
const typeOptions = computed(() =>
  types.value
    .filter((type) => type.is_active || String(type.id) === form.payroll_component_id)
    .map((type) => ({ value: String(type.id), label: type.name })),
)
const recurrenceOptions = computed(() => [
  { value: 'recurring', label: t('admin.payroll.assignments.recurring') },
  { value: 'once', label: t('admin.payroll.assignments.once') },
])

const formOpen = ref(false)
const editing = ref<StaffPayComponent | null>(null)
const form = reactive({
  staff_id: '',
  payroll_component_id: '',
  amount: '',
  recurrence: (props.kind === 'bonus' ? 'once' : 'recurring') as PayRecurrence,
  starts_on: '',
  ends_on: '',
  note: '',
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const pickedType = computed(() => types.value.find((type) => String(type.id) === form.payroll_component_id))

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

async function open(row: StaffPayComponent | null) {
  editing.value = row
  form.staff_id = row ? String(row.staff_id) : ''
  form.payroll_component_id = row ? String(row.payroll_component_id) : ''
  form.amount = row ? String(Number(row.amount)) : ''
  form.recurrence = row?.recurrence ?? (props.kind === 'bonus' ? 'once' : 'recurring')
  form.starts_on = row?.starts_on ?? today()
  form.ends_on = row?.ends_on ?? ''
  form.note = row?.note ?? ''
  errors.value = {}
  saveError.value = null
  formOpen.value = true
  if (staff.value.length === 0) staff.value = await staffService.listAll().catch(() => [])
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    payroll_component_id: Number(form.payroll_component_id),
    amount: Number(form.amount || 0),
    recurrence: form.recurrence,
    starts_on: form.starts_on,
    ends_on: form.recurrence === 'recurring' && form.ends_on ? form.ends_on : null,
    note: form.note.trim() || null,
  }
  try {
    if (editing.value) await staffPayComponentsService.update(editing.value.id, input)
    else await staffPayComponentsService.create({ ...input, staff_id: Number(form.staff_id) })
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.payroll.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(row: StaffPayComponent) {
  if (!(await confirmDialog.confirm({ message: t('admin.payroll.assignments.deleteConfirm', { name: row.component?.name ?? '', staff: row.staff?.name ?? '' }), danger: true }))) return
  actionError.value = null
  try {
    await staffPayComponentsService.remove(row.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(() => {
  void fetch()
  void loadTypes()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-0.5 text-sm">
        <button
          type="button"
          class="rounded-md px-3 py-1.5 font-medium"
          :class="view === 'staff' ? 'bg-primary-50 text-primary-800' : 'text-neutral-600 hover:text-neutral-900'"
          @click="view = 'staff'"
        >
          {{ t('admin.payroll.assignments.givenToStaff') }}
        </button>
        <button
          type="button"
          class="rounded-md px-3 py-1.5 font-medium"
          :class="view === 'types' ? 'bg-primary-50 text-primary-800' : 'text-neutral-600 hover:text-neutral-900'"
          @click="view = 'types'"
        >
          {{ t(`admin.payroll.${k}.types`) }}
        </button>
      </div>
      <template v-if="canManage">
        <BaseButton v-if="view === 'staff'" :disabled="types.length === 0" @click="open(null)">{{ t(`admin.payroll.${k}.give`) }}</BaseButton>
        <BaseButton v-else @click="openType(null)">{{ t(`admin.payroll.${k}.addType`) }}</BaseButton>
      </template>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Given to staff -->
    <template v-if="view === 'staff'">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-neutral-500">{{ t(`admin.payroll.${k}.hint`) }}</p>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="currentOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.payroll.assignments.currentOnly') }}
        </label>
      </div>
      <p v-if="!typesLoading && types.length === 0" class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ t(`admin.payroll.${k}.noTypesYet`) }}</p>

      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t(`admin.payroll.${k}.empty`) }}
      </p>
      <template v-else>
        <!-- Cards on a phone (below sm) -->
        <div class="space-y-2 sm:hidden">
          <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
            <div class="flex items-start justify-between gap-2">
              <p class="text-sm font-semibold text-neutral-800">{{ row.staff?.name ?? '—' }} <span class="font-normal text-neutral-500">({{ row.staff?.employee_code }})</span></p>
              <span class="shrink-0 text-sm font-semibold text-neutral-900">{{ amountOf(row) }}</span>
            </div>
            <p class="text-sm text-neutral-700">{{ row.component?.name ?? '—' }}</p>
            <p class="text-xs text-neutral-500">{{ periodOf(row) }}</p>
            <p v-if="row.note" class="text-xs text-neutral-600">{{ row.note }}</p>
            <div v-if="canManage" class="mt-2 flex justify-end gap-3">
              <EditIconButton @click="open(row)" />
              <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.organization.delete') }}</button>
            </div>
          </div>
        </div>

        <div class="hidden overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white sm:block">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-4 py-3 font-medium">{{ t('admin.payroll.staff') }}</th>
                <th class="px-4 py-3 font-medium">{{ t(`admin.payroll.${k}.item`) }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('admin.payroll.amount') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.payroll.assignments.when') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('admin.payroll.note') }}</th>
                <th v-if="canManage" class="px-4 py-3 text-right font-medium">{{ t('admin.organization.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in items" :key="row.id">
                <td class="px-4 py-3">
                  <p class="font-medium text-neutral-800">{{ row.staff?.name ?? '—' }}</p>
                  <p class="text-xs text-neutral-500">{{ row.staff?.employee_code }}</p>
                </td>
                <td class="px-4 py-3 text-neutral-700">{{ row.component?.name ?? '—' }}</td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums text-neutral-900">{{ amountOf(row) }}</td>
                <td class="px-4 py-3 text-neutral-700">{{ periodOf(row) }}</td>
                <td class="px-4 py-3 text-neutral-600">{{ row.note ?? '—' }}</td>
                <td v-if="canManage" class="px-4 py-3">
                  <div class="flex justify-end gap-2">
                    <EditIconButton @click="open(row)" />
                    <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('admin.organization.delete') }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />
    </template>

    <!-- Types -->
    <template v-else>
      <p class="mb-4 text-sm text-neutral-500">{{ t(`admin.payroll.${k}.typesHint`) }}</p>
      <div v-if="typesLoading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="types.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t(`admin.payroll.${k}.noTypesYet`) }}
      </p>
      <div v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="type in types" :key="type.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-neutral-800">{{ type.name }} <span class="font-normal text-neutral-500">({{ type.code }})</span></p>
            <BaseBadge :variant="type.is_active ? 'success' : 'neutral'">{{ type.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm text-neutral-700">{{ traits(type) }}</p>
          <p class="text-xs text-neutral-500">{{ t('admin.payroll.components.usage', { staff: type.staff_assignments_count ?? 0, structures: type.structure_items_count ?? 0 }) }}</p>
          <p v-if="type.description" class="text-xs text-neutral-600">{{ type.description }}</p>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="openType(type)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="removeType(type)">{{ t('admin.organization.delete') }}</button>
          </div>
        </div>
      </div>
    </template>

    <!-- Give to a staff member -->
    <BaseModal v-model="formOpen" :title="editing ? t(`admin.payroll.${k}.editGiven`) : t(`admin.payroll.${k}.give`)">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <SearchableSelect
          v-if="!editing"
          v-model="form.staff_id"
          required
          :options="staffOptions"
          :label="t('admin.payroll.staff')"
          :error="errors.staff_id?.[0]"
        />
        <p v-else class="text-sm text-neutral-700"><span class="text-neutral-500">{{ t('admin.payroll.staff') }}:</span> {{ editing.staff?.name }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect v-model="form.payroll_component_id" required :options="typeOptions" :placeholder="t('admin.payroll.structures.pickComponent')" :label="t(`admin.payroll.${k}.item`)" :error="errors.payroll_component_id?.[0]" />
          <BaseInput
            v-model="form.amount"
            type="number"
            required
            :label="pickedType?.calculation === 'percent_of_basic' ? t('admin.payroll.assignments.percentLabel') : t('admin.payroll.assignments.amountLabel')"
            :hint="pickedType?.calculation === 'percent_of_basic' ? undefined : t('admin.payroll.assignments.amountHint')"
            :error="errors.amount?.[0]"
          />
        </div>
        <BaseSelect
          :model-value="form.recurrence"
          :options="recurrenceOptions"
          :label="t('admin.payroll.assignments.how')"
          @update:model-value="form.recurrence = $event as PayRecurrence"
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput
            v-model="form.starts_on"
            type="date"
            required
            :label="form.recurrence === 'once' ? t('admin.payroll.assignments.payDate') : t('admin.payroll.assignments.startsOn')"
            :hint="form.recurrence === 'once' ? t('admin.payroll.assignments.payDateHint') : undefined"
            :error="errors.starts_on?.[0]"
          />
          <BaseInput
            v-if="form.recurrence === 'recurring'"
            v-model="form.ends_on"
            type="date"
            :label="t('admin.payroll.assignments.endsOn')"
            :hint="t('admin.payroll.assignments.endsOnHint')"
            :error="errors.ends_on?.[0]"
          />
        </div>
        <BaseInput v-model="form.note" :label="t('admin.payroll.note')" :error="errors.note?.[0]" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- Type form -->
    <BaseModal v-model="typeFormOpen" :title="editingType ? t(`admin.payroll.${k}.editType`) : t(`admin.payroll.${k}.addType`)">
      <form class="space-y-4" @submit.prevent="saveType">
        <BaseAlert v-if="typeSaveError" variant="danger">{{ typeSaveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="typeForm.code" required :label="t('admin.organization.code')" :error="typeErrors.code?.[0]" />
          <BaseInput v-model="typeForm.name" required :label="t('admin.payroll.components.name')" :error="typeErrors.name?.[0]" />
        </div>
        <BaseSelect
          :model-value="typeForm.calculation"
          :options="calculationOptions"
          :label="t('admin.payroll.components.calculation')"
          @update:model-value="typeForm.calculation = $event as PayCalculation"
        />
        <BaseInput v-model="typeForm.description" :label="t('admin.organization.description')" />
        <div class="space-y-2">
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="typeForm.affects_tax" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t(kind === 'deduction' ? 'admin.payroll.components.beforeTaxLabel' : 'admin.payroll.components.taxableLabel') }}
          </label>
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="typeForm.affects_social_security" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t(kind === 'deduction' ? 'admin.payroll.components.beforeNssfLabel' : 'admin.payroll.components.nssfLabel') }}
          </label>
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="typeForm.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.organization.statusActive') }}
          </label>
        </div>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="typeFormOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="typeSaving" @click="saveType">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

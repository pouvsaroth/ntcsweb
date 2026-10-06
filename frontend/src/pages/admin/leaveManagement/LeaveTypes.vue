<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { leaveTypesService, type LeaveGender, type LeaveType } from '@/services/leaveManagement'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { genderLabel } from '@/utils/gender'

/**
 * HRM > Leave Management > Leave types — Annual, Sick, Maternity, ... How
 * many days a year each gives is set under Leave policies. Cards on a
 * phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canManage = computed(() => auth.can('leave-management.manage'))

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<LeaveType>((query) => leaveTypesService.list(query))

/** "Paid · Half days · File required · Female only" — what sets this type apart. */
function traits(type: LeaveType): string {
  return [
    type.is_paid ? t('admin.leaveManagement.types.paid') : t('admin.leaveManagement.types.unpaid'),
    type.allow_half_day ? t('admin.leaveManagement.types.halfDays') : null,
    type.requires_attachment ? t('admin.leaveManagement.types.fileRequired') : null,
    type.gender ? t('admin.leaveManagement.types.genderOnly', { gender: genderLabel(type.gender) }) : null,
  ]
    .filter(Boolean)
    .join(' · ')
}

const columns = computed(() => [
  { key: 'name', label: t('admin.leaveManagement.types.name') },
  { key: 'traits', label: t('admin.leaveManagement.types.rules') },
  { key: 'policies', label: t('admin.leaveManagement.types.policies') },
  { key: 'is_active', label: t('admin.organization.status') },
  ...(canManage.value ? [{ key: 'actions', label: t('admin.organization.actions'), align: 'text-right' }] : []),
])

// --- Form ----------------------------------------------------------------------

const genderOptions = computed(() => [
  { value: '', label: t('admin.leaveManagement.types.everyone') },
  { value: 'male', label: genderLabel('male') },
  { value: 'female', label: genderLabel('female') },
])

const formOpen = ref(false)
const editing = ref<LeaveType | null>(null)
const form = reactive({
  code: '',
  name: '',
  color: '#22c55e',
  is_paid: true,
  allow_half_day: true,
  requires_attachment: false,
  gender: '' as LeaveGender | '',
  description: '',
  is_active: true,
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const actionError = ref<string | null>(null)

function open(type: LeaveType | null) {
  editing.value = type
  form.code = type?.code ?? ''
  form.name = type?.name ?? ''
  form.color = type?.color ?? '#22c55e'
  form.is_paid = type?.is_paid ?? true
  form.allow_half_day = type?.allow_half_day ?? true
  form.requires_attachment = type?.requires_attachment ?? false
  form.gender = type?.gender ?? ''
  form.description = type?.description ?? ''
  form.is_active = type?.is_active ?? true
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = { ...form, gender: form.gender || null, description: form.description.trim() || null }
  try {
    if (editing.value) await leaveTypesService.update(editing.value.id, input)
    else await leaveTypesService.create(input)
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.leaveManagement.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(type: LeaveType) {
  if (!(await confirmDialog.confirm({ message: t('admin.leaveManagement.types.deleteConfirm', { name: type.name }), danger: true }))) return
  actionError.value = null
  try {
    await leaveTypesService.remove(type.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-4 flex items-center justify-between gap-3">
      <p class="text-sm text-neutral-500">{{ t('admin.leaveManagement.types.hint') }}</p>
      <BaseButton v-if="canManage" @click="open(null)">{{ t('admin.leaveManagement.types.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.leaveManagement.types.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <p class="flex items-center gap-2 text-sm font-semibold text-neutral-800">
              <span class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: row.color ?? '#9ca3af' }" />
              {{ row.name }} <span class="font-normal text-neutral-500">({{ row.code }})</span>
            </p>
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
          </div>
          <p class="mt-1 text-sm text-neutral-700">{{ traits(row) }}</p>
          <p class="text-xs text-neutral-500">{{ t('admin.leaveManagement.types.policiesN', { count: row.policies_count ?? 0 }) }}</p>
          <div v-if="canManage" class="mt-2 flex justify-end gap-3">
            <EditIconButton @click="open(row)" />
            <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.organization.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.leaveManagement.types.empty')">
        <template #cell-name="{ row }">
          <p class="flex items-center gap-2 font-medium text-neutral-800">
            <span class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: row.color ?? '#9ca3af' }" />
            {{ row.name }}
          </p>
          <p class="text-xs text-neutral-500">{{ row.code }}</p>
        </template>
        <template #cell-traits="{ row }">{{ traits(row as LeaveType) }}</template>
        <template #cell-policies="{ row }">{{ row.policies_count ?? 0 }}</template>
        <template #cell-is_active="{ row }">
          <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="open(row as LeaveType)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as LeaveType)">{{ t('admin.organization.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal v-model="formOpen" :title="editing ? t('admin.leaveManagement.types.edit') : t('admin.leaveManagement.types.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.code" required :label="t('admin.organization.code')" :error="errors.code?.[0]" />
          <BaseInput v-model="form.name" required :label="t('admin.leaveManagement.types.name')" :error="errors.name?.[0]" />
          <BaseSelect
            :model-value="form.gender"
            :options="genderOptions"
            :label="t('admin.leaveManagement.types.gender')"
            :hint="t('admin.leaveManagement.types.genderHint')"
            :error="errors.gender?.[0]"
            @update:model-value="form.gender = $event as LeaveGender | ''"
          />
          <div>
            <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('admin.leaveManagement.types.color') }}</label>
            <input v-model="form.color" type="color" class="h-[38px] w-full cursor-pointer rounded-lg border border-neutral-300 p-1" />
          </div>
        </div>
        <BaseInput v-model="form.description" :label="t('admin.organization.description')" />
        <div class="space-y-2">
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="form.is_paid" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.leaveManagement.types.paid') }}
          </label>
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="form.allow_half_day" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.leaveManagement.types.allowHalfDay') }}
          </label>
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="form.requires_attachment" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.leaveManagement.types.requiresAttachment') }}
          </label>
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('admin.organization.statusActive') }}
          </label>
        </div>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

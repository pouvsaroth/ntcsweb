<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { rolesService, type Role } from '@/services/roles'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{
  modelValue: boolean
  /** Present when editing; absent when creating a new role. May be one of the 4 built-in system roles — see isSystemRole below. */
  role?: Role | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

type Action =
  | 'view'
  | 'create'
  | 'update'
  | 'delete'
  | 'assign'
  | 'cancel'
  | 'send'
  | 'deactivate'
  | 'approve'
  | 'reject'
  | 'pay'
  | 'export'
  | 'transfer'
  | 'return'
  | 'retire'
  | 'dispose'
  | 'lost'
  | 'found'
  | 'resolve'
  | 'complete'

/**
 * Mirrors App\Support\Authorization\Permissions::catalog() on the backend,
 * minus the "Platform" group — those permissions only ever matter for
 * platform (super admin) accounts, so offering them here would just be noise
 * for a school admin building a job-title role like "Accountant".
 *
 * A matrix (module rows × action columns), not a flat checkbox list: every
 * module repeats the same four action words, so a flat list reads as
 * "View, Create, Update, Delete, View, Create, Update, Delete, …" with no
 * visual distinction between them at a glance.
 */
const MODULES: { name: string; actions: Partial<Record<Action, string>> }[] = [
  { name: 'School settings', actions: { view: 'tenant-settings.view', update: 'tenant-settings.update' } },
  { name: 'Users', actions: { view: 'users.view', create: 'users.create', update: 'users.update', delete: 'users.delete' } },
  {
    name: 'Roles',
    actions: {
      view: 'roles.view',
      create: 'roles.create',
      update: 'roles.update',
      delete: 'roles.delete',
      assign: 'roles.assign',
    },
  },
  { name: 'Positions', actions: { view: 'positions.view', create: 'positions.create', update: 'positions.update', delete: 'positions.delete' } },
  { name: 'Staff', actions: { view: 'staff.view', create: 'staff.create', update: 'staff.update', delete: 'staff.delete' } },
  { name: 'Teachers', actions: { view: 'teachers.view', create: 'teachers.create', update: 'teachers.update', delete: 'teachers.delete' } },
  { name: 'Students', actions: { view: 'students.view', create: 'students.create', update: 'students.update', delete: 'students.delete' } },
  { name: 'Classrooms', actions: { view: 'classrooms.view', create: 'classrooms.create', update: 'classrooms.update', delete: 'classrooms.delete' } },
  { name: 'Books', actions: { view: 'books.view', create: 'books.create', update: 'books.update', delete: 'books.delete' } },
  { name: 'Classes', actions: { view: 'classes.view', create: 'classes.create', update: 'classes.update', delete: 'classes.delete' } },
  { name: 'Enrollments', actions: { view: 'enrollments.view', create: 'enrollments.create', update: 'enrollments.update', delete: 'enrollments.delete' } },
  { name: 'Attendance', actions: { view: 'attendance.view', create: 'attendance.create', update: 'attendance.update' } },
  { name: 'Home slides', actions: { view: 'home-slides.view', create: 'home-slides.create', update: 'home-slides.update', delete: 'home-slides.delete' } },
  { name: 'Gallery', actions: { view: 'gallery.view', create: 'gallery.create', update: 'gallery.update', delete: 'gallery.delete' } },
  { name: 'Programs', actions: { view: 'programs.view', create: 'programs.create', update: 'programs.update', delete: 'programs.delete' } },
  { name: 'Products', actions: { view: 'products.view', create: 'products.create', update: 'products.update', delete: 'products.delete' } },
  { name: 'Invoices', actions: { view: 'invoices.view', create: 'invoices.create', update: 'invoices.update', cancel: 'invoices.cancel' } },
  { name: 'Payments', actions: { view: 'payments.view', create: 'payments.create', update: 'payments.update', cancel: 'payments.cancel' } },
  { name: 'Receipts', actions: { view: 'receipts.view' } },
  { name: 'Billing reports', actions: { view: 'billing-reports.view' } },
  { name: 'Billing notifications', actions: { send: 'notifications.send' } },
  { name: 'Accounts', actions: { view: 'accounts.view', create: 'accounts.create', update: 'accounts.update', deactivate: 'accounts.deactivate' } },
  { name: 'Income', actions: { view: 'income.view', create: 'income.create', update: 'income.update', cancel: 'income.cancel' } },
  {
    name: 'Expenses',
    actions: {
      view: 'expense.view',
      create: 'expense.create',
      update: 'expense.update',
      approve: 'expense.approve',
      reject: 'expense.reject',
      pay: 'expense.pay',
      cancel: 'expense.cancel',
    },
  },
  { name: 'Transactions', actions: { view: 'transactions.view', create: 'transactions.create' } },
  { name: 'Financial reports', actions: { view: 'reports.financial.view', export: 'reports.financial.export' } },
  {
    // Categories/Locations/Departments/Suppliers all share these exact same
    // four slugs (see AssetCategoryPolicy and friends) rather than getting
    // their own near-duplicate rows here — toggling "Assets" already
    // controls all of them together.
    name: 'Assets',
    actions: {
      view: 'assets.view',
      create: 'assets.create',
      update: 'assets.update',
      delete: 'assets.delete',
      assign: 'assets.assign',
      return: 'assets.return',
      transfer: 'assets.transfer',
      retire: 'assets.retire',
      dispose: 'assets.dispose',
      lost: 'assets.mark_lost',
      found: 'assets.mark_found',
    },
  },
  { name: 'Asset issues', actions: { view: 'assets.issue.view', create: 'assets.issue.create', update: 'assets.issue.update', resolve: 'assets.issue.resolve' } },
  { name: 'Asset repairs', actions: { view: 'assets.repair.view', create: 'assets.repair.create', update: 'assets.repair.update', complete: 'assets.repair.complete' } },
  { name: 'Repair shops', actions: { view: 'assets.repair.view', create: 'assets.create', update: 'assets.update', delete: 'assets.delete' } },
  { name: 'Asset maintenance', actions: { view: 'assets.maintenance.view', create: 'assets.maintenance.create', update: 'assets.maintenance.update' } },
  { name: 'Asset reports', actions: { view: 'assets.reports.view', export: 'assets.reports.export' } },
  { name: 'System', actions: { view: 'audit-logs.view' } },
]

const COLUMNS: Action[] = [
  'view', 'create', 'update', 'delete', 'approve', 'reject', 'pay', 'cancel', 'deactivate', 'export', 'send', 'assign',
  'return', 'transfer', 'retire', 'dispose', 'lost', 'found', 'resolve', 'complete',
]

const isEditing = computed(() => props.role != null)

/**
 * A system role's name/slug/level are locked server-side (see
 * UpdateRoleRequest — it simply drops those fields for a system role), so
 * disabling them here isn't just cosmetic: it keeps the form honest about
 * what a submit will actually change. Permissions/description stay editable
 * — that's the entire point of surfacing these 4 rows on this screen.
 */
const isSystemRole = computed(() => props.role?.is_system ?? false)

const form = reactive({
  name: '',
  description: '',
  level: 10,
  permissions: [] as string[],
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

function columnLabel(action: Action): string {
  return t(`admin.roles.action${action.charAt(0).toUpperCase()}${action.slice(1)}`)
}

function isChecked(slug: string): boolean {
  return form.permissions.includes(slug)
}

function toggle(slug: string, checked: boolean) {
  form.permissions = checked ? [...form.permissions, slug] : form.permissions.filter((p) => p !== slug)
}

function resetForm() {
  form.name = props.role?.name ?? ''
  form.description = props.role?.description ?? ''
  form.level = props.role?.level ?? 10
  form.permissions = props.role?.permissions ?? []
  errors.value = {}
  generalError.value = null
}

watch(
  () => [props.modelValue, props.role] as const,
  ([open]) => {
    if (open) resetForm()
  },
  { immediate: true },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    if (isEditing.value) {
      await rolesService.update(props.role!.id, form)
    } else {
      await rolesService.create(form)
    }

    emit('saved')
    emit('update:modelValue', false)
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.roles.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="isEditing ? t('admin.roles.editTitle') : t('admin.roles.createTitle')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseAlert v-if="isSystemRole" variant="info">{{ t('admin.roles.systemRoleEditHint') }}</BaseAlert>

      <div class="grid grid-cols-2 gap-4">
        <BaseInput
          v-model="form.name"
          required
          :disabled="isSystemRole"
          :label="t('admin.roles.roleName')"
          :error="errors.name?.[0]"
        />
        <BaseInput
          :model-value="String(form.level)"
          type="number"
          required
          :disabled="isSystemRole"
          :label="t('admin.roles.level')"
          :hint="t('admin.roles.levelHint')"
          :error="errors.level?.[0]"
          @update:model-value="form.level = Number($event) || 0"
        />
      </div>

      <BaseInput v-model="form.description" :label="t('admin.roles.description')" :error="errors.description?.[0]" />

      <div>
        <label class="mb-2 block text-sm font-medium text-neutral-700">{{ t('admin.roles.permissions') }}</label>
        <div class="max-h-80 overflow-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <thead class="sticky top-0 bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
              <tr>
                <th class="px-3 py-2 text-left">{{ t('admin.roles.module') }}</th>
                <th v-for="column in COLUMNS" :key="column" class="px-3 py-2 text-center">{{ columnLabel(column) }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="module in MODULES" :key="module.name">
                <td class="px-3 py-2 text-neutral-700">{{ module.name }}</td>
                <td v-for="column in COLUMNS" :key="column" class="px-3 py-2 text-center">
                  <input
                    v-if="module.actions[column]"
                    type="checkbox"
                    :checked="isChecked(module.actions[column]!)"
                    class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                    @change="toggle(module.actions[column]!, ($event.target as HTMLInputElement).checked)"
                  />
                  <span v-else class="text-neutral-300">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

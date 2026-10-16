<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDateTime } from '@/utils/date'

import { rolesApi, staffApi, type Role, type Staff } from '../api'
import StaffFormModal from '../components/StaffFormModal.vue'
import TemporaryPasswordModal from '../components/TemporaryPasswordModal.vue'
import { useSession } from '../session'

const { t } = useI18n()
const session = useSession()
const confirmDialog = useConfirmDialogStore()

const { items, meta, loading, error, search, setSearch, setPage, setSort, sort, fetch } = usePaginatedResource<Staff>((query) =>
  staffApi.list(query),
)

const canWrite = computed(() => session.can('platform.staff.write'))
const roles = ref<Role[]>([])

const columns = computed(() => [
  { key: 'name', label: t('bo.staff.name'), sortable: true },
  { key: 'email', label: t('bo.staff.email'), sortable: true },
  { key: 'status', label: t('bo.staff.status') },
  { key: 'roles', label: t('bo.staff.roles') },
  { key: 'two_factor_enabled', label: t('bo.staff.twoFactor') },
  { key: 'last_login_at', label: t('bo.staff.lastLogin'), sortable: true },
  ...(canWrite.value ? [{ key: 'actions', label: t('bo.staff.actions'), align: 'text-right' }] : []),
])

const formOpen = ref(false)
const editing = ref<Staff | null>(null)
const temp = ref({ open: false, name: '', password: '' })
const message = ref<string | null>(null)
const actionError = ref<string | null>(null)

function openAdd(): void {
  editing.value = null
  formOpen.value = true
}

function openEdit(staff: Staff): void {
  editing.value = staff
  formOpen.value = true
}

function showTemporary(staff: Staff, password: string): void {
  temp.value = { open: true, name: staff.name, password }
  void fetch()
}

async function run(action: () => Promise<unknown>): Promise<void> {
  actionError.value = null
  message.value = null
  try {
    await action()
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? (e.fieldError('status') ?? e.fieldError('roles') ?? e.message) : t('bo.common.failed')
  }
}

async function toggleStatus(staff: Staff): Promise<void> {
  if (staff.status === 'active') {
    const ok = await confirmDialog.confirm({ message: t('bo.staff.disableConfirm', { name: staff.name }), danger: true })
    if (!ok) return
  }
  await run(() => staffApi.update(staff.id, { status: staff.status === 'active' ? 'disabled' : 'active' }))
}

async function resetTwoFactor(staff: Staff): Promise<void> {
  if (!(await confirmDialog.confirm({ message: t('bo.staff.resetTwoFactorConfirm', { name: staff.name }), danger: true }))) return
  await run(() => staffApi.resetTwoFactor(staff.id))
}

async function resetPassword(staff: Staff): Promise<void> {
  if (!(await confirmDialog.confirm({ message: t('bo.staff.resetPasswordConfirm', { name: staff.name }) }))) return
  await run(async () => {
    const result = await staffApi.resetPassword(staff.id)
    temp.value = { open: true, name: staff.name, password: result.temporary_password }
  })
}

onMounted(() => {
  void fetch()
  // Role names for the form; someone without platform.role.read edits names only.
  if (session.can('platform.role.read')) void rolesApi.list().then((r) => (roles.value = r))
})
</script>

<template>
  <div>
    <div class="mb-6 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('bo.staff.title') }}</h1>
      <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center">
        <div class="w-full sm:w-72">
          <BaseInput :model-value="search" :placeholder="t('bo.staff.search')" @update:model-value="setSearch" />
        </div>
        <BaseButton v-if="canWrite" @click="openAdd">{{ t('bo.staff.add') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>
    <BaseAlert v-if="message" variant="success" class="mb-4">{{ message }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('bo.staff.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">
                {{ row.name }}
                <span v-if="row.id === session.staff?.id" class="text-xs font-normal text-neutral-500">({{ t('bo.staff.you') }})</span>
              </p>
              <p class="truncate text-sm text-neutral-500">{{ row.email }}</p>
            </div>
            <BaseBadge :variant="row.status === 'active' ? 'success' : 'danger'">{{ t(`bo.staff.${row.status}`) }}</BaseBadge>
          </div>
          <div class="mt-2 flex flex-wrap gap-1">
            <BaseBadge v-for="role in row.roles" :key="role.id" variant="primary">{{ role.name }}</BaseBadge>
            <BaseBadge v-if="!row.two_factor_enabled" variant="warning">2FA: {{ t('bo.staff.pending') }}</BaseBadge>
          </div>
          <p class="mt-2 text-xs text-neutral-500">
            {{ t('bo.staff.lastLogin') }}: {{ row.last_login_at ? formatDateTime(row.last_login_at) : t('bo.staff.never') }}
          </p>
          <div v-if="canWrite" class="mt-3 flex flex-wrap gap-2 border-t border-neutral-100 pt-3">
            <BaseButton size="sm" variant="outline" @click="openEdit(row)">{{ t('bo.staff.edit') }}</BaseButton>
            <template v-if="row.id !== session.staff?.id">
              <BaseButton size="sm" variant="ghost" @click="resetPassword(row)">{{ t('bo.staff.resetPassword') }}</BaseButton>
              <BaseButton size="sm" variant="ghost" @click="resetTwoFactor(row)">{{ t('bo.staff.resetTwoFactor') }}</BaseButton>
              <BaseButton size="sm" :variant="row.status === 'active' ? 'danger' : 'outline'" @click="toggleStatus(row)">
                {{ row.status === 'active' ? t('bo.staff.disable') : t('bo.staff.enable') }}
              </BaseButton>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- Tablet / desktop: table -->
    <div class="hidden sm:block">
      <DataTable
        :columns="columns"
        :rows="items"
        row-key="id"
        :loading="loading"
        :sort="sort"
        :empty-message="t('bo.staff.empty')"
        @sort="(col) => setSort(sort === col ? `-${col}` : col)"
      >
        <template #cell-name="{ row }">
          <span class="font-medium text-neutral-900">{{ row.name }}</span>
          <span v-if="row.id === session.staff?.id" class="ml-1 text-xs text-neutral-500">({{ t('bo.staff.you') }})</span>
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="row.status === 'active' ? 'success' : 'danger'">{{ t(`bo.staff.${row.status}`) }}</BaseBadge>
          <BaseBadge v-if="row.must_change_password" variant="neutral" class="ml-1">{{ t('bo.staff.mustChange') }}</BaseBadge>
        </template>
        <template #cell-roles="{ row }">
          <div class="flex flex-wrap gap-1">
            <BaseBadge v-for="role in row.roles" :key="role.id" variant="primary">{{ role.name }}</BaseBadge>
          </div>
        </template>
        <template #cell-two_factor_enabled="{ row }">
          <BaseBadge :variant="row.two_factor_enabled ? 'success' : 'warning'">
            {{ row.two_factor_enabled ? t('bo.account.twoFactorOn') : t('bo.staff.pending') }}
          </BaseBadge>
        </template>
        <template #cell-last_login_at="{ row }">{{ row.last_login_at ? formatDateTime(row.last_login_at) : t('bo.staff.never') }}</template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-1">
            <BaseButton size="sm" variant="ghost" @click="openEdit(row)">{{ t('bo.staff.edit') }}</BaseButton>
            <template v-if="row.id !== session.staff?.id">
              <BaseButton size="sm" variant="ghost" @click="resetPassword(row)">{{ t('bo.staff.resetPassword') }}</BaseButton>
              <BaseButton size="sm" variant="ghost" @click="resetTwoFactor(row)">{{ t('bo.staff.resetTwoFactor') }}</BaseButton>
              <BaseButton size="sm" :variant="row.status === 'active' ? 'danger' : 'outline'" @click="toggleStatus(row)">
                {{ row.status === 'active' ? t('bo.staff.disable') : t('bo.staff.enable') }}
              </BaseButton>
            </template>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" class="mt-4" @update:page="setPage" />

    <StaffFormModal v-model="formOpen" :staff="editing" :roles="roles" @saved="fetch" @created="showTemporary" />
    <TemporaryPasswordModal v-model="temp.open" :name="temp.name" :password="temp.password" />
  </div>
</template>

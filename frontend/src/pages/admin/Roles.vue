<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import RoleFormModal from '@/components/admin/RoleFormModal.vue'
import ActionIconButton from '@/components/ui/ActionIconButton.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { rolesService, type Role } from '@/services/roles'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const items = ref<Role[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const modalOpen = ref(false)
const editingRole = ref<Role | null>(null)

const columns = computed(() => [
  { key: 'name', label: t('admin.roles.columnName') },
  { key: 'level', label: t('admin.roles.columnLevel') },
  { key: 'users_count', label: t('admin.roles.columnUsers') },
  { key: 'actions', label: t('admin.roles.columnActions'), align: 'text-right' },
])

async function load() {
  loading.value = true
  error.value = null

  try {
    const result = await rolesService.list()
    items.value = result.data
  } catch {
    error.value = t('admin.roles.saveFailed')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingRole.value = null
  modalOpen.value = true
}

function openEdit(role: Role) {
  editingRole.value = role
  modalOpen.value = true
}

/** Built-in roles are found by slug in the code, so only a school's own roles can be deleted. */
function canDelete(role: Role): boolean {
  return !role.is_system && auth.can('roles.delete')
}

async function remove(role: Role) {
  if (!(await confirmDialog.confirm({ message: t('admin.roles.deleteConfirm', { name: role.name }), danger: true }))) return
  error.value = null

  try {
    await rolesService.remove(role.id)
    await load()
  } catch (e) {
    // A role still held by users or positions is refused; the server says how many.
    const message = e instanceof ApiRequestError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : null
    error.value = message || t('admin.roles.deleteFailed')
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.roles.title') }}</h1>
      </div>
      <BaseButton v-if="auth.can('roles.create')" @click="openCreate">{{ t('admin.roles.addRole') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Phone: one card per role. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.roles.emptyMessage') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-medium text-neutral-900">
                {{ row.name }}
                <BaseBadge v-if="row.is_system" variant="neutral" class="ml-1" :title="t('admin.roles.builtInNoDelete')">{{ t('admin.roles.systemBadge') }}</BaseBadge>
              </p>
              <p class="text-xs text-neutral-500">
                {{ t('admin.roles.columnLevel') }} {{ row.level }} · {{ t('admin.roles.columnUsers') }} {{ row.users_count ?? 0 }}
              </p>
            </div>
            <div class="flex shrink-0 items-center">
              <EditIconButton v-if="auth.can('roles.update')" @click="openEdit(row)" />
              <ActionIconButton v-if="canDelete(row)" variant="danger" :title="t('admin.roles.deleteRole')" @click="remove(row)">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                  />
                </svg>
              </ActionIconButton>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.roles.emptyMessage')">
        <template #cell-name="{ row }">
          {{ row.name }}
          <BaseBadge v-if="row.is_system" variant="neutral" class="ml-2" :title="t('admin.roles.builtInNoDelete')">{{ t('admin.roles.systemBadge') }}</BaseBadge>
        </template>
        <template #cell-users_count="{ row }">{{ row.users_count ?? 0 }}</template>
        <template #cell-actions="{ row }">
          <!-- Every role can be renamed (a built-in one keeps its slug and level — see
               UpdateRoleRequest); only the school's own roles can be deleted. -->
          <div class="flex justify-end">
            <EditIconButton v-if="auth.can('roles.update')" @click="openEdit(row)" />
            <ActionIconButton v-if="canDelete(row)" variant="danger" :title="t('admin.roles.deleteRole')" @click="remove(row)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                />
              </svg>
            </ActionIconButton>
          </div>
        </template>
      </DataTable>
    </div>

    <RoleFormModal v-model="modalOpen" :role="editingRole" @saved="load" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CreateUserModal from '@/components/admin/CreateUserModal.vue'
import EditUserModal from '@/components/admin/EditUserModal.vue'
import ResetPasswordModal from '@/components/admin/ResetPasswordModal.vue'
import ActionIconButton from '@/components/ui/ActionIconButton.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { adminUsersService } from '@/services/adminUsers'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types/models'
import { formatDate } from '@/utils/date'

const { t } = useI18n()

const userStatusVariant: Record<User['status'], 'success' | 'warning' | 'danger' | 'neutral'> = {
  active: 'success',
  invited: 'neutral',
  suspended: 'danger',
  // Automatic — lifts itself once the student is Studying again or the staff
  // member is Active again (see the backend's StudentAccessService and
  // StaffLoginAccessService), so a softer colour than a suspension.
  inactive: 'warning',
  pending_approval: 'neutral',
}

const userStatusLabelKey: Record<User['status'], string> = {
  active: 'admin.users.statusActive',
  invited: 'admin.users.statusInvited',
  suspended: 'admin.users.statusSuspended',
  inactive: 'admin.users.statusInactive',
  pending_approval: 'admin.users.statusPendingApproval',
}
const auth = useAuthStore()

const { items, meta, loading, error, search, setSearch, setPage, setSort, sort, fetch } =
  usePaginatedResource<User>((query) => adminUsersService.list(query))

const columns = computed(() => [
  { key: 'name', label: t('admin.users.columnName'), sortable: true },
  { key: 'email', label: t('admin.users.columnEmail'), sortable: true },
  { key: 'status', label: t('admin.users.columnStatus') },
  { key: 'roles', label: t('admin.users.columnRoles') },
  { key: 'created_at', label: t('admin.users.columnJoined'), sortable: true },
  { key: 'actions', label: t('admin.users.columnActions'), align: 'text-right' },
])

const modalOpen = ref(false)

const editModalOpen = ref(false)
const editTarget = ref<User | null>(null)

function openEdit(user: User) {
  editTarget.value = user
  editModalOpen.value = true
}

const resetPasswordModalOpen = ref(false)
const resetPasswordTarget = ref<User | null>(null)

function openResetPassword(user: User) {
  resetPasswordTarget.value = user
  resetPasswordModalOpen.value = true
}

const actionMessage = ref<string | null>(null)
const actionError = ref<string | null>(null)

/** For a user stuck locked out of AuthController's one-device login rule — see AuthService::ensureNoOtherActiveDevice(). */
async function forceLogout(user: User) {
  if (!window.confirm(t('admin.users.forceLogoutConfirm', { name: user.name }))) return

  actionError.value = null
  actionMessage.value = null

  try {
    await adminUsersService.forceLogout(user.id)
    actionMessage.value = t('admin.users.forceLogoutSuccess', { name: user.name })
  } catch {
    actionError.value = t('admin.users.forceLogoutFailed')
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.users.title') }}</h1>
      <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center">
        <div class="w-full sm:w-72">
          <BaseInput
            :model-value="search"
            :placeholder="t('admin.users.searchPlaceholder')"
            @update:model-value="setSearch"
          />
        </div>
        <BaseButton @click="modalOpen = true">{{ t('admin.users.addUser') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>
    <BaseAlert v-if="actionMessage" variant="success" class="mb-4">{{ actionMessage }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.users.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
      <template #cell-email="{ row }">{{ row.email ?? row.phone ?? '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="userStatusVariant[row.status] ?? 'neutral'">
          {{ userStatusLabelKey[row.status] ? t(userStatusLabelKey[row.status]) : row.status }}
        </BaseBadge>
      </template>
      <template #cell-roles="{ row }">
        <div class="flex flex-wrap gap-1">
          <BaseBadge v-for="role in row.roles" :key="role.id" variant="primary">{{ role.name }}</BaseBadge>
        </div>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-1">
          <EditIconButton @click="openEdit(row)" />
          <ActionIconButton v-if="row.id !== auth.user?.id" :title="t('admin.users.resetPasswordTitle')" @click="openResetPassword(row)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"
              />
            </svg>
          </ActionIconButton>
          <ActionIconButton
            v-if="row.id !== auth.user?.id"
            variant="danger"
            :title="t('admin.users.forceLogoutTitle')"
            @click="forceLogout(row)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M8.25 9V5.25A2.25 2.25 0 0110.5 3h6a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0116.5 21h-6a2.25 2.25 0 01-2.25-2.25V15m-3 0l-3-3m0 0l3-3m-3 3H15"
              />
            </svg>
          </ActionIconButton>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CreateUserModal v-model="modalOpen" @saved="fetch" />
    <EditUserModal v-model="editModalOpen" :user="editTarget" @saved="fetch" />
    <ResetPasswordModal v-model="resetPasswordModalOpen" :user="resetPasswordTarget" />
  </div>
</template>

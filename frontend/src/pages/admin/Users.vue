<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CreateUserModal from '@/components/admin/CreateUserModal.vue'
import ResetPasswordModal from '@/components/admin/ResetPasswordModal.vue'
import ActionIconButton from '@/components/ui/ActionIconButton.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { adminUsersService } from '@/services/adminUsers'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types/models'

const { t } = useI18n()
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

const resetPasswordModalOpen = ref(false)
const resetPasswordTarget = ref<User | null>(null)

function openResetPassword(user: User) {
  resetPasswordTarget.value = user
  resetPasswordModalOpen.value = true
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

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.users.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-email="{ row }">{{ row.email ?? row.phone ?? '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'active' ? 'success' : row.status === 'suspended' ? 'danger' : 'neutral'">
          {{ row.status }}
        </BaseBadge>
      </template>
      <template #cell-roles="{ row }">
        <div class="flex flex-wrap gap-1">
          <BaseBadge v-for="role in row.roles" :key="role.id" variant="primary">{{ role.name }}</BaseBadge>
        </div>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end">
          <ActionIconButton v-if="row.id !== auth.user?.id" :title="t('admin.users.resetPasswordTitle')" @click="openResetPassword(row)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"
              />
            </svg>
          </ActionIconButton>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CreateUserModal v-model="modalOpen" @saved="fetch" />
    <ResetPasswordModal v-model="resetPasswordModalOpen" :user="resetPasswordTarget" />
  </div>
</template>

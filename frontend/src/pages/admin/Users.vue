<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CreateUserModal from '@/components/admin/CreateUserModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { adminUsersService } from '@/services/adminUsers'
import type { User } from '@/types/models'

const { t } = useI18n()

const { items, meta, loading, error, search, setSearch, setPage, setSort, sort, fetch } =
  usePaginatedResource<User>((query) => adminUsersService.list(query))

const columns = computed(() => [
  { key: 'name', label: t('admin.users.columnName'), sortable: true },
  { key: 'email', label: t('admin.users.columnEmail'), sortable: true },
  { key: 'status', label: t('admin.users.columnStatus') },
  { key: 'roles', label: t('admin.users.columnRoles') },
  { key: 'created_at', label: t('admin.users.columnJoined'), sortable: true },
])

const modalOpen = ref(false)

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
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CreateUserModal v-model="modalOpen" @saved="fetch" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import RoleFormModal from '@/components/admin/RoleFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { rolesService, type Role } from '@/services/roles'

const { t } = useI18n()

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

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.roles.title') }}</h1>
      </div>
      <BaseButton @click="openCreate">{{ t('admin.roles.addRole') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.roles.emptyMessage')">
      <template #cell-name="{ row }">
        {{ row.name }}
        <BaseBadge v-if="row.is_system" variant="neutral" class="ml-2">{{ t('admin.roles.systemBadge') }}</BaseBadge>
      </template>
      <template #cell-users_count="{ row }">{{ row.users_count ?? 0 }}</template>
      <template #cell-actions="{ row }">
        <!-- System roles are editable too now (RoleFormModal locks their
             name/level but leaves description/permissions open) — see
             RolePolicy::update() and UpdateRoleRequest for the backend side
             of that split. -->
        <div class="flex justify-end">
          <EditIconButton @click="openEdit(row)" />
        </div>
      </template>
    </DataTable>

    <RoleFormModal v-model="modalOpen" :role="editingRole" @saved="load" />
  </div>
</template>

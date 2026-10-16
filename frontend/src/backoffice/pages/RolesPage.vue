<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

import { rolesApi, type PermissionNode, type Role } from '../api'
import RoleFormModal from '../components/RoleFormModal.vue'
import { useSession } from '../session'

const { t, locale } = useI18n()
const session = useSession()
const confirmDialog = useConfirmDialogStore()

const roles = ref<Role[]>([])
const tree = ref<PermissionNode[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const canWrite = computed(() => session.can('platform.role.write'))

const columns = computed(() => [
  { key: 'name', label: t('bo.roles.name') },
  { key: 'permissions', label: t('bo.roles.permissions') },
  { key: 'staff_count', label: t('bo.roles.staffCount'), align: 'text-right' },
  ...(canWrite.value ? [{ key: 'actions', label: t('bo.staff.actions'), align: 'text-right' }] : []),
])

/** Leaf key → its label in the current language, for the badges. */
const labels = computed(() => {
  const map = new Map<string, string>()
  const walk = (n: PermissionNode): void => {
    map.set(n.key, locale.value === 'km' && n.label_km ? n.label_km : n.label)
    n.children.forEach(walk)
  }
  tree.value.forEach(walk)
  return map
})

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    ;[roles.value, tree.value] = await Promise.all([rolesApi.list(), rolesApi.permissions()])
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
}

const formOpen = ref(false)
const editing = ref<Role | null>(null)

function openForm(role: Role | null): void {
  editing.value = role
  formOpen.value = true
}

const copying = ref<Role | null>(null)
const copyName = ref('')
const copyError = ref<string | undefined>(undefined)

function openCopy(role: Role): void {
  copying.value = role
  copyName.value = `${role.name} ${t('bo.roles.copySuffix')}`
  copyError.value = undefined
}

async function saveCopy(): Promise<void> {
  if (!copying.value) return
  try {
    await rolesApi.copy(copying.value.id, copyName.value)
    copying.value = null
    await load()
  } catch (e) {
    copyError.value = e instanceof ApiRequestError ? (e.fieldError('name') ?? e.message) : t('bo.common.failed')
  }
}

async function remove(role: Role): Promise<void> {
  if (!(await confirmDialog.confirm({ message: t('bo.roles.removeConfirm', { name: role.name }), danger: true }))) return
  try {
    await rolesApi.remove(role.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between gap-3">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('bo.roles.title') }}</h1>
      <BaseButton v-if="canWrite" @click="openForm(null)">{{ t('bo.roles.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Phone: cards -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <div v-else class="space-y-2">
        <div v-for="role in roles" :key="role.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-medium text-neutral-900">{{ role.name }}</p>
              <p v-if="role.description" class="text-sm text-neutral-500">{{ role.description }}</p>
            </div>
            <span class="text-sm text-neutral-500">{{ role.staff_count ?? 0 }} {{ t('bo.roles.staffCount') }}</span>
          </div>
          <div class="mt-2 flex flex-wrap gap-1">
            <BaseBadge v-if="role.is_owner" variant="primary">{{ t('bo.roles.allOf') }}</BaseBadge>
            <template v-else>
              <BaseBadge v-for="key in role.permissions" :key="key">{{ labels.get(key) ?? key }}</BaseBadge>
              <span v-if="!role.permissions.length" class="text-xs text-neutral-400">{{ t('bo.roles.none') }}</span>
            </template>
          </div>
          <div v-if="canWrite" class="mt-3 flex flex-wrap gap-2 border-t border-neutral-100 pt-3">
            <template v-if="!role.is_owner">
              <BaseButton size="sm" variant="outline" @click="openForm(role)">{{ t('bo.roles.edit') }}</BaseButton>
              <BaseButton size="sm" variant="ghost" @click="openCopy(role)">{{ t('bo.roles.copy') }}</BaseButton>
              <BaseButton size="sm" variant="danger" @click="remove(role)">{{ t('bo.roles.remove') }}</BaseButton>
            </template>
            <template v-else>
              <BaseButton size="sm" variant="ghost" @click="openCopy(role)">{{ t('bo.roles.copy') }}</BaseButton>
              <span class="self-center text-xs text-neutral-500">{{ t('bo.roles.ownerHint') }}</span>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- Tablet / desktop: table -->
    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="roles" row-key="id" :loading="loading">
        <template #cell-name="{ row }">
          <p class="font-medium text-neutral-900">{{ row.name }}</p>
          <p v-if="row.is_owner" class="text-xs text-neutral-500">{{ t('bo.roles.ownerHint') }}</p>
          <p v-else-if="row.description" class="text-xs text-neutral-500">{{ row.description }}</p>
        </template>
        <template #cell-permissions="{ row }">
          <div class="flex max-w-xl flex-wrap gap-1">
            <BaseBadge v-if="row.is_owner" variant="primary">{{ t('bo.roles.allOf') }}</BaseBadge>
            <template v-else>
              <BaseBadge v-for="key in row.permissions" :key="key">{{ labels.get(key) ?? key }}</BaseBadge>
              <span v-if="!row.permissions.length" class="text-xs text-neutral-400">{{ t('bo.roles.none') }}</span>
            </template>
          </div>
        </template>
        <template #cell-staff_count="{ row }">{{ row.staff_count ?? 0 }}</template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-1">
            <BaseButton v-if="!row.is_owner" size="sm" variant="ghost" @click="openForm(row)">{{ t('bo.roles.edit') }}</BaseButton>
            <BaseButton size="sm" variant="ghost" @click="openCopy(row)">{{ t('bo.roles.copy') }}</BaseButton>
            <BaseButton v-if="!row.is_owner" size="sm" variant="danger" @click="remove(row)">{{ t('bo.roles.remove') }}</BaseButton>
          </div>
        </template>
      </DataTable>
    </div>

    <RoleFormModal v-model="formOpen" :role="editing" :tree="tree" @saved="load" />

    <BaseModal :model-value="copying !== null" :title="t('bo.roles.copy')" size="sm" @update:model-value="copying = null">
      <form id="bo-role-copy" @submit.prevent="saveCopy">
        <BaseInput v-model="copyName" :label="t('bo.roles.copyName')" :error="copyError" required />
      </form>
      <template #footer>
        <BaseButton variant="ghost" @click="copying = null">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="bo-role-copy">{{ t('bo.common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

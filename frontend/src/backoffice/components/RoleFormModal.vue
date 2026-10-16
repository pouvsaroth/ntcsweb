<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { ApiRequestError } from '@/types/api'

import { rolesApi, type PermissionNode, type Role } from '../api'
import PermissionTree from './PermissionTree.vue'

/** Add (role = null) or edit a role. The Owner role never opens here. */
const props = defineProps<{ modelValue: boolean; role: Role | null; tree: PermissionNode[] }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; saved: [] }>()

const { t } = useI18n()

const name = ref('')
const description = ref('')
const permissions = ref<string[]>([])
const saving = ref(false)
const error = ref<ApiRequestError | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    name.value = props.role?.name ?? ''
    description.value = props.role?.description ?? ''
    permissions.value = [...(props.role?.permissions ?? [])]
    error.value = null
  },
)

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  const body = { name: name.value, description: description.value || null, permissions: permissions.value }
  try {
    if (props.role) await rolesApi.update(props.role.id, body)
    else await rolesApi.create(body)
    emit('saved')
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="role ? t('bo.roles.edit') : t('bo.roles.add')"
    size="lg"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="bo-role-form" class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="error && !error.errors" variant="danger">{{ error.message }}</BaseAlert>
      <BaseInput v-model="name" :label="t('bo.roles.name')" :error="error?.fieldError('name')" required />
      <BaseInput v-model="description" :label="t('bo.roles.description')" :error="error?.fieldError('description')" />
      <div>
        <p class="mb-2 text-sm font-medium text-neutral-700">{{ t('bo.roles.permissions') }}</p>
        <PermissionTree v-model="permissions" :tree="tree" />
      </div>
    </form>

    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="bo-role-form" :loading="saving">{{ t('bo.common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

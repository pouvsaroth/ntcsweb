<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { ApiRequestError } from '@/types/api'

import { staffApi, type Role, type Staff } from '../api'

/** Add (staff = null) or edit one back-office account. Adding returns its temporary password via `created`. */
const props = defineProps<{ modelValue: boolean; staff: Staff | null; roles: Role[] }>()
const emit = defineEmits<{
  'update:modelValue': [open: boolean]
  saved: []
  created: [staff: Staff, temporaryPassword: string]
}>()

const { t } = useI18n()

const name = ref('')
const email = ref('')
const roleIds = ref<number[]>([])
const saving = ref(false)
const error = ref<ApiRequestError | null>(null)

const editing = computed(() => props.staff !== null)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    name.value = props.staff?.name ?? ''
    email.value = props.staff?.email ?? ''
    roleIds.value = props.staff?.roles.map((r) => r.id) ?? []
    error.value = null
  },
)

function toggleRole(id: number, on: boolean): void {
  roleIds.value = on ? [...roleIds.value, id] : roleIds.value.filter((r) => r !== id)
}

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    if (props.staff) {
      await staffApi.update(props.staff.id, { name: name.value, roles: roleIds.value })
      emit('saved')
    } else {
      const result = await staffApi.create({ name: name.value, email: email.value, roles: roleIds.value })
      emit('created', result.staff, result.temporary_password)
    }
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
    :title="editing ? t('bo.staff.edit') : t('bo.staff.add')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form id="bo-staff-form" class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="error && (!error.errors || error.fieldError('roles'))" variant="danger">
        {{ error.fieldError('roles') ?? error.message }}
      </BaseAlert>
      <BaseInput v-model="name" :label="t('bo.staff.name')" :error="error?.fieldError('name')" required />
      <BaseInput
        v-model="email"
        :label="t('bo.staff.email')"
        type="email"
        :disabled="editing"
        :error="error?.fieldError('email')"
        required
      />

      <fieldset v-if="roles.length">
        <legend class="mb-2 text-sm font-medium text-neutral-700">{{ t('bo.staff.roles') }}</legend>
        <div class="space-y-2">
          <label v-for="role in roles" :key="role.id" class="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              class="mt-0.5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              :checked="roleIds.includes(role.id)"
              @change="toggleRole(role.id, ($event.target as HTMLInputElement).checked)"
            />
            <span>
              <span class="font-medium text-neutral-800">{{ role.name }}</span>
              <span v-if="role.description" class="block text-xs text-neutral-500">{{ role.description }}</span>
            </span>
          </label>
        </div>
      </fieldset>
    </form>

    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton type="submit" form="bo-staff-form" :loading="saving">{{ t('bo.common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

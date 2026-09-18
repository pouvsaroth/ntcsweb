<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { adminUsersService } from '@/services/adminUsers'
import { rolesService, type Role } from '@/services/roles'
import { ApiRequestError } from '@/types/api'
import type { User } from '@/types/models'

const props = defineProps<{
  modelValue: boolean
  user: User | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const roles = ref<Role[]>([])
const roleOptions = computed(() => roles.value.map((role) => ({ value: String(role.id), label: role.name })))

/** A student-linked account's role is always forced to Student — see StoreUserRequest — so it's not offered here. */
const isStudentLinked = computed(() => props.user?.student_id != null)

const form = reactive({ name: '', phone: '', email: '', role_id: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

onMounted(async () => {
  roles.value = await rolesService.listAll()
})

watch(
  () => [props.modelValue, props.user] as const,
  ([open, user]) => {
    if (!open || !user) return
    form.name = user.name
    form.phone = user.phone ?? ''
    form.email = user.email ?? ''
    form.role_id = user.roles?.[0] ? String(user.roles[0].id) : ''
    errors.value = {}
    generalError.value = null
  },
  { immediate: true },
)

function close() {
  emit('update:modelValue', false)
}

async function submit() {
  if (!props.user) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await adminUsersService.update(props.user.id, {
      name: form.name,
      phone: form.phone,
      email: form.email,
      ...(isStudentLinked.value ? {} : { role_id: form.role_id ? Number(form.role_id) : undefined }),
    })
    emit('saved')
    close()
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.users.saveFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.users.editTitle')" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="user" class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>

      <BaseInput v-model="form.name" required :label="t('admin.users.name')" :error="errors.name?.[0]" />
      <BaseInput v-model="form.phone" :label="t('admin.users.phone')" :error="errors.phone?.[0]" />
      <BaseInput v-model="form.email" type="email" :label="t('admin.users.email')" :error="errors.email?.[0]" />

      <BaseSelect
        v-if="!isStudentLinked"
        v-model="form.role_id"
        :options="roleOptions"
        :placeholder="t('admin.users.selectRole')"
        :label="t('admin.users.role')"
        :error="errors.role_id?.[0]"
      />
      <p v-else class="text-sm text-neutral-500">{{ t('admin.users.roleLockedToStudent') }}</p>
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="close">{{ t('common.cancel') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

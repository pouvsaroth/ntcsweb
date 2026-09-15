<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { adminUsersService } from '@/services/adminUsers'
import { ApiRequestError } from '@/types/api'
import type { User } from '@/types/models'

const props = defineProps<{
  modelValue: boolean
  user: User | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()

const form = reactive({ password: '', password_confirmation: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const saved = ref(false)

watch(
  () => [props.modelValue, props.user] as const,
  ([open]) => {
    if (!open) return
    form.password = ''
    form.password_confirmation = ''
    errors.value = {}
    generalError.value = null
    saved.value = false
  },
  { immediate: true },
)

async function submit() {
  if (!props.user) return

  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await adminUsersService.resetPassword(props.user.id, form.password, form.password_confirmation)
    saved.value = true
    emit('saved')
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('admin.users.resetPasswordFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('admin.users.resetPasswordTitle')" @update:model-value="emit('update:modelValue', $event)">
    <div v-if="user">
      <BaseAlert v-if="saved" variant="success" class="mb-4">{{ t('admin.users.resetPasswordSuccess') }}</BaseAlert>

      <template v-else>
        <BaseAlert v-if="generalError" variant="danger" class="mb-4">{{ generalError }}</BaseAlert>

        <div class="mb-4 rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
          {{ user.name }} — {{ user.email ?? user.phone ?? '—' }}
        </div>

        <form class="space-y-4" @submit.prevent="submit">
          <BaseInput
            v-model="form.password"
            type="password"
            autocomplete="new-password"
            required
            :label="t('admin.users.newPassword')"
            :error="errors.password?.[0]"
          />
          <BaseInput
            v-model="form.password_confirmation"
            type="password"
            autocomplete="new-password"
            required
            :label="t('admin.users.confirmNewPassword')"
          />
        </form>
      </template>
    </div>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t(saved ? 'common.close' : 'common.cancel') }}</BaseButton>
      <BaseButton v-if="!saved" :loading="submitting" @click="submit">{{ t('admin.users.resetPasswordAction') }}</BaseButton>
    </template>
  </BaseModal>
</template>

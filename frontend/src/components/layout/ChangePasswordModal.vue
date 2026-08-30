<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { authService } from '@/services/auth'
import { ApiRequestError } from '@/types/api'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t } = useI18n()

const form = reactive({ current_password: '', password: '', password_confirmation: '' })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const saved = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return

    form.current_password = ''
    form.password = ''
    form.password_confirmation = ''
    errors.value = {}
    generalError.value = null
    saved.value = false
  },
)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await authService.changePassword(form)
    saved.value = true
    form.current_password = ''
    form.password = ''
    form.password_confirmation = ''
  } catch (error) {
    if (error instanceof ApiRequestError && error.errors) {
      errors.value = error.errors
    } else {
      generalError.value = error instanceof ApiRequestError ? error.message : t('common.changePasswordFailed')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="modelValue"
    :title="t('common.changePassword')"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseAlert v-if="generalError" variant="danger">{{ generalError }}</BaseAlert>
      <BaseAlert v-if="saved" variant="success">{{ t('common.changePasswordSuccess') }}</BaseAlert>

      <BaseInput
        v-model="form.current_password"
        type="password"
        required
        autocomplete="current-password"
        :label="t('common.currentPassword')"
        :error="errors.current_password?.[0]"
      />
      <BaseInput
        v-model="form.password"
        type="password"
        required
        autocomplete="new-password"
        :label="t('common.newPassword')"
        :error="errors.password?.[0]"
      />
      <BaseInput
        v-model="form.password_confirmation"
        type="password"
        required
        autocomplete="new-password"
        :label="t('common.confirmNewPassword')"
      />
    </form>

    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="submitting" @click="submit">{{ t('common.save') }}</BaseButton>
    </template>
  </BaseModal>
</template>

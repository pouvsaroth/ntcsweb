<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { authService } from '@/services/auth'
import { ApiRequestError } from '@/types/api'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const form = reactive({
  password: '',
  password_confirmation: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await authService.resetPassword({
      token: String(route.query.token ?? ''),
      email: String(route.query.email ?? ''),
      tenant: typeof route.query.tenant === 'string' ? route.query.tenant : undefined,
      ...form,
    })
    await router.push('/login')
  } catch (error) {
    if (error instanceof ApiRequestError) {
      error.errors ? (errors.value = error.errors) : (generalError.value = error.message)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div>
    <h2 class="mb-1 text-lg font-semibold text-neutral-900">{{ t('auth.resetPassword.title') }}</h2>
    <p class="mb-6 text-sm text-neutral-500">{{ t('auth.resetPassword.subtitle') }}</p>

    <BaseAlert v-if="generalError" variant="danger" class="mb-4">{{ generalError }}</BaseAlert>

    <form class="space-y-4" @submit.prevent="submit">
      <BaseInput
        v-model="form.password"
        type="password"
        :label="t('auth.resetPassword.newPassword')"
        autocomplete="new-password"
        required
        :error="errors.password?.[0]"
      />
      <BaseInput
        v-model="form.password_confirmation"
        type="password"
        :label="t('auth.resetPassword.confirmPassword')"
        autocomplete="new-password"
        required
      />
      <BaseButton type="submit" :loading="submitting" block>{{ t('auth.resetPassword.submit') }}</BaseButton>
    </form>
  </div>
</template>

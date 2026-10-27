<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { authService } from '@/services/auth'
import { apiGet } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()

/**
 * Someone may already be signed in on this browser (e.g. staff opening a
 * customer's invite link). Their name when we can read it; a session of
 * another school (refused with TENANT_MISMATCH) still counts as signed in.
 */
const signedIn = ref(false)
const signedInName = ref('')
onMounted(async () => {
  try {
    const user = await apiGet<{ name: string }>('/auth/me')
    signedIn.value = true
    signedInName.value = user.name
  } catch (error) {
    signedIn.value = error instanceof ApiRequestError && error.code === 'TENANT_MISMATCH'
  }
})

const form = reactive({
  password: '',
  password_confirmation: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const isInvite = route.query.invite === '1'

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    // This link is for another account: end the session signed in here first.
    if (signedIn.value) {
      await auth.logout()
      signedIn.value = false
    }
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
    <!-- ?invite=1: a new school owner's first password, from the back office's invite (same token flow as a reset). -->
    <h2 class="mb-1 text-lg font-semibold text-neutral-900">{{ isInvite ? t('auth.resetPassword.inviteTitle') : t('auth.resetPassword.title') }}</h2>
    <p class="mb-6 text-sm text-neutral-500">{{ isInvite ? t('auth.resetPassword.inviteSubtitle') : t('auth.resetPassword.subtitle') }}</p>

    <BaseAlert v-if="signedIn" variant="info" class="mb-4">{{ t('auth.resetPassword.signedInNote', { name: signedInName || t('auth.resetPassword.someone') }) }}</BaseAlert>
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
      <BaseButton type="submit" :loading="submitting" block>{{ isInvite ? t('auth.resetPassword.inviteSubmit') : t('auth.resetPassword.submit') }}</BaseButton>
    </form>
  </div>
</template>

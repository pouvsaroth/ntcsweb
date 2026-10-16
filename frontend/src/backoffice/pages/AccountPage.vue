<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { ApiRequestError } from '@/types/api'

import { authApi } from '../api'
import { useSession } from '../session'

/** Your own account: the password (forced here after a temporary one) and two-step sign-in. */
const { t } = useI18n()
const session = useSession()
const router = useRouter()

const form = ref({ current_password: '', password: '', password_confirmation: '' })
const saving = ref(false)
const errors = ref<ApiRequestError | null>(null)
const saved = ref(false)

async function changePassword(): Promise<void> {
  saving.value = true
  errors.value = null
  saved.value = false
  const wasForced = session.mustChangePassword
  try {
    session.set(await authApi.changePassword(form.value))
    form.value = { current_password: '', password: '', password_confirmation: '' }
    saved.value = true
    if (wasForced) await router.replace('/')
  } catch (e) {
    errors.value = e instanceof ApiRequestError ? e : new ApiRequestError(t('bo.common.failed'), 0)
  } finally {
    saving.value = false
  }
}

const regenPassword = ref('')
const regenBusy = ref(false)
const regenError = ref<string | null>(null)
const newCodes = ref<string[]>([])

async function regenerate(): Promise<void> {
  regenBusy.value = true
  regenError.value = null
  try {
    newCodes.value = (await authApi.regenerateRecoveryCodes(regenPassword.value)).recovery_codes
    regenPassword.value = ''
    session.set(await authApi.session())
  } catch (e) {
    regenError.value = e instanceof ApiRequestError ? (e.fieldError('current_password') ?? e.message) : t('bo.common.failed')
  } finally {
    regenBusy.value = false
  }
}
</script>

<template>
  <div class="max-w-xl space-y-6">
    <h1 class="text-xl font-semibold text-neutral-900">{{ t('bo.account.title') }}</h1>

    <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
      <h2 class="mb-1 font-semibold text-neutral-900">{{ t('bo.password.title') }}</h2>
      <BaseAlert v-if="session.mustChangePassword" variant="warning" class="my-3">{{ t('bo.password.forcedHint') }}</BaseAlert>
      <BaseAlert v-if="saved" variant="success" class="my-3">{{ t('bo.password.saved') }}</BaseAlert>
      <BaseAlert v-if="errors && !errors.errors" variant="danger" class="my-3">{{ errors.message }}</BaseAlert>

      <form class="mt-3 space-y-4" @submit.prevent="changePassword">
        <BaseInput
          v-model="form.current_password"
          :label="t('bo.password.current')"
          type="password"
          autocomplete="current-password"
          :error="errors?.fieldError('current_password')"
          required
        />
        <BaseInput
          v-model="form.password"
          :label="t('bo.password.new')"
          type="password"
          autocomplete="new-password"
          :error="errors?.fieldError('password')"
          required
        />
        <BaseInput
          v-model="form.password_confirmation"
          :label="t('bo.password.confirm')"
          type="password"
          autocomplete="new-password"
          required
        />
        <BaseButton type="submit" :loading="saving">{{ t('bo.password.save') }}</BaseButton>
      </form>
    </section>

    <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
      <div class="flex items-center justify-between gap-3">
        <h2 class="font-semibold text-neutral-900">{{ t('bo.account.twoFactor') }}</h2>
        <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">{{ t('bo.account.twoFactorOn') }}</span>
      </div>
      <p class="mt-1 text-sm text-neutral-600">{{ t('bo.account.recoveryLeft', { n: session.session?.recovery_codes_left ?? 0 }) }}</p>

      <form class="mt-4 space-y-3" @submit.prevent="regenerate">
        <p class="text-sm text-neutral-600">{{ t('bo.account.regenerateHint') }}</p>
        <BaseInput
          v-model="regenPassword"
          :label="t('bo.password.current')"
          type="password"
          autocomplete="current-password"
          :error="regenError ?? undefined"
          required
        />
        <BaseButton type="submit" variant="outline" :loading="regenBusy">{{ t('bo.account.regenerate') }}</BaseButton>
      </form>

      <div v-if="newCodes.length" class="mt-4">
        <p class="mb-2 text-sm text-neutral-600">{{ t('bo.signIn.recoveryHint') }}</p>
        <ul class="grid grid-cols-2 gap-2 rounded bg-neutral-100 p-3 font-mono text-sm text-neutral-800">
          <li v-for="c in newCodes" :key="c">{{ c }}</li>
        </ul>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import QRCode from 'qrcode'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { ApiRequestError } from '@/types/api'

import { authApi, primeCsrf, type Session } from '../api'
import { setLocale } from '../i18n'
import { useSession } from '../session'

/**
 * Password, then a code. A first sign-in instead enrols the authenticator
 * (QR + one code) and shows the recovery codes once before going in.
 */
type Step = 'password' | 'code' | 'setup' | 'recovery'

const { t, locale } = useI18n()
const router = useRouter()
const route = useRoute()
const session = useSession()

const step = ref<Step>('password')
const email = ref('')
const password = ref('')
const code = ref('')
const busy = ref(false)
const error = ref<string | null>(null)

const qr = ref<string | null>(null)
const secret = ref('')
const recoveryCodes = ref<string[]>([])
const copied = ref(false)
let signedIn: Session | null = null

function fail(e: unknown): void {
  if (e instanceof ApiRequestError && e.code === 'SIGN_IN_EXPIRED') {
    restart()
    error.value = t('bo.signIn.expired')
    return
  }
  error.value = e instanceof ApiRequestError ? (e.fieldError('email') ?? e.fieldError('code') ?? e.message) : t('bo.common.failed')
}

function restart(): void {
  step.value = 'password'
  password.value = ''
  code.value = ''
  error.value = null
}

async function submitPassword(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await primeCsrf()
    const result = await authApi.login(email.value, password.value)
    if (result.two_factor === 'required') {
      step.value = 'code'
    } else {
      const setup = await authApi.setup()
      secret.value = setup.secret
      qr.value = await QRCode.toDataURL(setup.otpauth_uri, { margin: 1, width: 200 })
      step.value = 'setup'
    }
  } catch (e) {
    fail(e)
  } finally {
    busy.value = false
  }
}

async function submitCode(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await finish(await authApi.twoFactor(code.value))
  } catch (e) {
    fail(e)
  } finally {
    busy.value = false
  }
}

async function submitSetup(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    const result = await authApi.confirm(code.value)
    signedIn = result
    recoveryCodes.value = result.recovery_codes
    step.value = 'recovery'
  } catch (e) {
    fail(e)
  } finally {
    busy.value = false
  }
}

async function copyCodes(): Promise<void> {
  try {
    await navigator.clipboard.writeText(recoveryCodes.value.join('\n'))
    copied.value = true
  } catch {
    // Clipboard blocked: the codes are on screen to write down.
  }
}

async function finish(next: Session | null = signedIn): Promise<void> {
  if (!next) return
  session.set(next)
  const target = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/'
  await router.replace(target)
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-secondary-900 px-4 py-12">
    <div class="w-full max-w-sm">
      <div class="mb-6 flex items-end justify-between text-white">
        <div>
          <p class="text-xl font-bold">{{ t('bo.brand') }}</p>
          <p class="text-xs text-secondary-300">{{ t('bo.brandSub') }}</p>
        </div>
        <button type="button" class="rounded px-2 py-1 text-sm hover:bg-white/10" @click="setLocale(locale === 'km' ? 'en' : 'km')">
          {{ t('bo.language') }}
        </button>
      </div>

      <div class="rounded-[--radius-card] bg-white p-6 shadow-[--shadow-card] sm:p-8">
        <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

        <form v-if="step === 'password'" class="space-y-4" @submit.prevent="submitPassword">
          <h1 class="text-lg font-semibold text-neutral-900">{{ t('bo.signIn.title') }}</h1>
          <BaseInput v-model="email" :label="t('bo.signIn.email')" type="email" autocomplete="username" required />
          <BaseInput v-model="password" :label="t('bo.signIn.password')" type="password" autocomplete="current-password" required />
          <BaseButton type="submit" block :loading="busy">{{ t('bo.signIn.continue') }}</BaseButton>
        </form>

        <form v-else-if="step === 'code'" class="space-y-4" @submit.prevent="submitCode">
          <h1 class="text-lg font-semibold text-neutral-900">{{ t('bo.signIn.codeTitle') }}</h1>
          <p class="text-sm text-neutral-600">{{ t('bo.signIn.codeHint') }}</p>
          <BaseInput v-model="code" :label="t('bo.signIn.code')" autocomplete="one-time-code" required />
          <BaseButton type="submit" block :loading="busy">{{ t('bo.signIn.verify') }}</BaseButton>
          <button type="button" class="w-full text-center text-sm text-neutral-500 hover:underline" @click="restart">
            {{ t('bo.signIn.back') }}
          </button>
        </form>

        <form v-else-if="step === 'setup'" class="space-y-4" @submit.prevent="submitSetup">
          <h1 class="text-lg font-semibold text-neutral-900">{{ t('bo.signIn.setupTitle') }}</h1>
          <p class="text-sm text-neutral-600">{{ t('bo.signIn.setupHint') }}</p>
          <img v-if="qr" :src="qr" alt="" class="mx-auto h-48 w-48" />
          <div>
            <p class="text-xs text-neutral-500">{{ t('bo.signIn.setupManual') }}</p>
            <p class="mt-1 rounded bg-neutral-100 px-2 py-1 font-mono text-xs break-all text-neutral-800">{{ secret }}</p>
          </div>
          <BaseInput v-model="code" :label="t('bo.signIn.code')" autocomplete="one-time-code" required />
          <BaseButton type="submit" block :loading="busy">{{ t('bo.signIn.verify') }}</BaseButton>
          <button type="button" class="w-full text-center text-sm text-neutral-500 hover:underline" @click="restart">
            {{ t('bo.signIn.back') }}
          </button>
        </form>

        <div v-else class="space-y-4">
          <h1 class="text-lg font-semibold text-neutral-900">{{ t('bo.signIn.recoveryTitle') }}</h1>
          <p class="text-sm text-neutral-600">{{ t('bo.signIn.recoveryHint') }}</p>
          <ul class="grid grid-cols-2 gap-2 rounded bg-neutral-100 p-3 font-mono text-sm text-neutral-800">
            <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
          </ul>
          <BaseButton variant="outline" block @click="copyCodes">
            {{ copied ? t('bo.signIn.recoveryCopied') : t('bo.signIn.recoveryCopy') }}
          </BaseButton>
          <BaseButton block @click="finish()">{{ t('bo.signIn.recoveryDone') }}</BaseButton>
        </div>
      </div>
    </div>
  </div>
</template>

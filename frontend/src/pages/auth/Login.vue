<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { ERP_HOST } from '@/config'
import { authService } from '@/services/auth'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'
import { ApiRequestError } from '@/types/api'

const auth = useAuthStore()
const site = useSiteStore()
const router = useRouter()
const route = useRoute()
const { t } = useI18n()

/**
 * The shared ERP domain has no hostname to infer a school from and, unlike
 * any other central domain (localhost, admin.ntcsweb.com), it's meant for
 * ordinary school accounts, not just a lone platform Super Admin — so
 * instead of typing a school code from memory, the identifier is looked up
 * first (see continueWithIdentity()) and the matching school(s) are shown as
 * a pick, or filled in automatically when there's only one. Every other
 * central domain keeps today's single-step manual field below unchanged.
 */
const isErpDomain = window.location.hostname === ERP_HOST

// A subdomain (production) already implies the school, so /public/settings
// resolves and this never shows. On a central domain (localhost, no
// subdomain) it 404s — see useSiteStore's `resolved` — and there is
// genuinely no way to know which school to check credentials against
// without asking. Superseded by the identity lookup above on the ERP domain.
const showSchoolField = computed(() => site.loaded && !site.resolved && !isErpDomain)

// Pre-filled from ?tenant=slug so a school-specific login link still works
// (e.g. shared by an admin) without a real subdomain to infer it from.
// Typed as free text rather than picked from a fetched list — a slug is
// short and stable, and typing it is one fewer network round trip and one
// less thing that can fail before the person even gets to their password.
const school = ref(typeof route.query.tenant === 'string' ? route.query.tenant : '')

const form = reactive({ login: '', password: '', remember: false })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

// --- ERP domain: identity-first step ---

const identityStep = ref(isErpDomain)
const checkingIdentity = ref(false)
const identityLookedUp = ref(false)
const tenantOptions = ref<{ id: number; slug: string; name: string }[]>([])

async function continueWithIdentity() {
  if (!form.login.trim()) return

  checkingIdentity.value = true
  try {
    tenantOptions.value = await authService.tenantsForLogin(form.login.trim())
    school.value = tenantOptions.value.length === 1 ? tenantOptions.value[0].slug : ''
    identityLookedUp.value = true
    identityStep.value = false
  } finally {
    checkingIdentity.value = false
  }
}

function changeIdentity() {
  identityStep.value = true
  identityLookedUp.value = false
  tenantOptions.value = []
  school.value = ''
}

/**
 * Remembers only the phone/email, never the password — a raw password
 * sitting in localStorage is readable by any script on the page (an XSS
 * exposure the rest of the app doesn't otherwise have), and doesn't buy
 * anything the browser's own password manager doesn't already do more
 * safely. The `autocomplete="username"`/`"current-password"` attributes
 * below are what let the browser itself offer to save and autofill the
 * password — this only takes care of the identifier field.
 */
const REMEMBERED_LOGIN_KEY = 'ntcsweb.remembered_login'

onMounted(() => {
  const remembered = localStorage.getItem(REMEMBERED_LOGIN_KEY)
  if (remembered) {
    form.login = remembered
    form.remember = true
  }
})

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await auth.login({ ...form, tenant: school.value.trim() || undefined }, showSchoolField.value || isErpDomain)

    if (form.remember) {
      localStorage.setItem(REMEMBERED_LOGIN_KEY, form.login)
    } else {
      localStorage.removeItem(REMEMBERED_LOGIN_KEY)
    }

    // A student logs into the public website itself, not the admin panel —
    // there's nothing there for them (no admin permissions) and the whole
    // point of a student login is unlocking their enrolled course's video
    // lessons/invoices while staying on the site they arrived on. Everyone
    // else (staff/teacher/admin) keeps going to /admin as before.
    const fallback = auth.hasRole('student') ? '/' : '/admin'
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : fallback
    await router.push(redirect)
  } catch (error) {
    if (error instanceof ApiRequestError) {
      if (error.errors) {
        errors.value = error.errors
      } else {
        generalError.value = error.message
      }
    } else {
      generalError.value = t('auth.login.genericError')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div>
    <h2 class="mb-1 text-lg font-semibold text-neutral-900">{{ t('auth.login.title') }}</h2>
    <p class="mb-6 text-sm text-neutral-500">{{ t('auth.login.subtitle') }}</p>

    <BaseAlert v-if="generalError" variant="danger" class="mb-4">{{ generalError }}</BaseAlert>
    <!-- On the ERP flow, step 2 hides the identifier field (already collected in step 1), which is where errors.login normally renders — so surface it here instead. -->
    <BaseAlert v-if="isErpDomain && !identityStep && errors.login?.[0]" variant="danger" class="mb-4">{{ errors.login[0] }}</BaseAlert>

    <!-- ERP domain, step 1: who is signing in, before any school is known. -->
    <form v-if="identityStep" class="space-y-4" @submit.prevent="continueWithIdentity">
      <BaseInput
        v-model="form.login"
        type="text"
        :label="t('auth.login.identifier')"
        autocomplete="username"
        required
      />
      <BaseButton type="submit" :loading="checkingIdentity" block>{{ t('auth.login.continue') }}</BaseButton>
    </form>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <div v-if="isErpDomain" class="flex items-center justify-between rounded-lg bg-neutral-50 px-3 py-2 text-sm">
        <span class="truncate text-neutral-600">{{ form.login }}</span>
        <button type="button" class="shrink-0 font-medium text-secondary-600 hover:text-secondary-700" @click="changeIdentity">
          {{ t('auth.login.change') }}
        </button>
      </div>

      <div v-if="isErpDomain && tenantOptions.length > 1">
        <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('auth.login.selectSchool') }}</label>
        <div class="space-y-2">
          <label
            v-for="option in tenantOptions"
            :key="option.id"
            class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm"
            :class="school === option.slug ? 'border-primary-500 ring-1 ring-primary-500' : 'border-neutral-300'"
          >
            <input v-model="school" type="radio" :value="option.slug" class="text-primary-600 focus:ring-primary-500" />
            {{ option.name }}
          </label>
        </div>
      </div>

      <BaseAlert v-if="isErpDomain && identityLookedUp && tenantOptions.length === 0" variant="info">
        {{ t('auth.login.noSchoolsFound') }}
      </BaseAlert>

      <!--
        Deliberately not `required`: a platform Super Admin account belongs
        to no school and must be able to sign in with this left blank — see
        schoolHint below and AuthService::authenticate()'s inTenant(null).
        A regular school account that leaves it blank just gets the normal
        "credentials do not match" error, same as typing the wrong slug.
      -->
      <BaseInput
        v-if="showSchoolField || (isErpDomain && identityLookedUp && tenantOptions.length === 0)"
        v-model="school"
        type="text"
        :label="t('auth.school')"
        :placeholder="t('auth.schoolPlaceholder')"
        :hint="t('auth.schoolHint')"
      />

      <BaseInput
        v-if="!isErpDomain"
        v-model="form.login"
        type="text"
        :label="t('auth.login.identifier')"
        autocomplete="username"
        required
        :error="errors.login?.[0]"
      />
      <BaseInput
        v-model="form.password"
        type="password"
        :label="t('auth.login.password')"
        autocomplete="current-password"
        required
        :error="errors.password?.[0]"
      />

      <div class="flex items-center justify-between text-sm">
        <label class="flex items-center gap-2 text-neutral-600">
          <input v-model="form.remember" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('auth.login.rememberMe') }}
        </label>
        <RouterLink to="/forgot-password" class="font-medium text-secondary-600 hover:text-secondary-700">
          {{ t('auth.login.forgotPassword') }}
        </RouterLink>
      </div>

      <BaseButton type="submit" :loading="submitting" block>{{ t('auth.login.submit') }}</BaseButton>
    </form>
  </div>
</template>

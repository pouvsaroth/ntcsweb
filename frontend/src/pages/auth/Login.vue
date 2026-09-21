<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { ERP_HOST } from '@/config'
import { loginRequiresTenantSelection, type LoginTenantChoice } from '@/services/auth'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'
import { ApiRequestError } from '@/types/api'

const auth = useAuthStore()
const site = useSiteStore()
const router = useRouter()
const route = useRoute()
const { t } = useI18n()

/**
 * The shared ERP domain has no hostname to infer a school from, but the
 * login form itself still only ever asks for an identity and a password —
 * see AuthController::loginAcrossTenants(), which checks the password
 * against every account across every school that matches before this page
 * ever needs to know which one is meant. Every other central domain
 * (localhost, admin.ntcsweb.com) keeps the manual school field below.
 */
const isErpDomain = window.location.hostname === ERP_HOST

// A subdomain (production) already implies the school, so /public/settings
// resolves and this never shows. On a central domain (localhost, no
// subdomain) it 404s — see useSiteStore's `resolved` — and there is
// genuinely no way to know which school to check credentials against
// without asking. Not shown on the ERP domain, which never needs it at all.
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

// --- Ambiguous login: more than one school verified, no password re-entry ---

const tenantChoices = ref<LoginTenantChoice[]>([])
const selectionToken = ref<string | null>(null)
const selectingTenant = ref(false)

async function chooseTenant(tenantId: number) {
  if (!selectionToken.value) return

  selectingTenant.value = true
  generalError.value = null

  try {
    await auth.selectTenant({ selection_token: selectionToken.value, tenant_id: tenantId, remember: form.remember })
    await afterLogin()
  } catch (error) {
    // The token is single-use and short-lived — most likely it expired.
    // Back to the plain form rather than leaving a dead-end picker up.
    tenantChoices.value = []
    selectionToken.value = null
    generalError.value = error instanceof ApiRequestError ? error.message : t('auth.login.genericError')
  } finally {
    selectingTenant.value = false
  }
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

async function afterLogin() {
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
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const result = await auth.login({ ...form, tenant: school.value.trim() || undefined }, showSchoolField.value || isErpDomain)

    if (loginRequiresTenantSelection(result)) {
      tenantChoices.value = result.tenants
      selectionToken.value = result.selection_token
      return
    }

    await afterLogin()
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

    <!-- The password already checked out at more than one school — just pick which one, nothing else. -->
    <div v-if="tenantChoices.length > 0">
      <p class="mb-3 text-sm text-neutral-600">{{ t('auth.login.selectSchool') }}</p>
      <div class="space-y-2">
        <button
          v-for="option in tenantChoices"
          :key="option.id"
          type="button"
          :disabled="selectingTenant"
          class="flex w-full items-center rounded-lg border border-neutral-300 px-3 py-2.5 text-left text-sm hover:border-primary-400 hover:bg-primary-50 disabled:opacity-50"
          @click="chooseTenant(option.id)"
        >
          {{ option.name }}
        </button>
      </div>
    </div>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <!--
        Deliberately not `required`: a platform Super Admin account belongs
        to no school and must be able to sign in with this left blank — see
        schoolHint below and AuthService::authenticate()'s inTenant(null).
        A regular school account that leaves it blank just gets the normal
        "credentials do not match" error, same as typing the wrong slug.
      -->
      <BaseInput
        v-if="showSchoolField"
        v-model="school"
        type="text"
        :label="t('auth.school')"
        :placeholder="t('auth.schoolPlaceholder')"
        :hint="t('auth.schoolHint')"
      />

      <BaseInput
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

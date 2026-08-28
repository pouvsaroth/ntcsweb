<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { useTenantOptions } from '@/composables/useTenantOptions'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'
import { ApiRequestError } from '@/types/api'

const auth = useAuthStore()
const site = useSiteStore()
const router = useRouter()
const route = useRoute()
const { t } = useI18n()

// A subdomain (production) already implies the school, so /public/settings
// resolves and this never shows. On a central domain (localhost, no
// subdomain) it 404s — see useSiteStore's `resolved` — and there is
// genuinely no way to know which school to check credentials against
// without asking.
const showSchoolField = computed(() => site.loaded && !site.resolved)

const { options: tenantOptions, loading: tenantsLoading, error: tenantsError, retry: retryTenants } = useTenantOptions()
const schoolOptions = computed(() => tenantOptions.value.map((t) => ({ value: t.slug, label: t.name })))

// Pre-selected from ?tenant=slug so a school-specific login link still works
// (e.g. shared by an admin) without a real subdomain to infer it from.
const school = ref(typeof route.query.tenant === 'string' ? route.query.tenant : '')

// Once the list loads, if nothing was pre-selected and there's exactly one
// school, picking it automatically saves a click — today there's only ever
// one tenant in local development.
watch(tenantOptions, (opts) => {
  if (!school.value && opts.length === 1) school.value = opts[0]!.slug
})

const form = reactive({ login: '', password: '', remember: false })
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await auth.login({ ...form, tenant: school.value.trim() || undefined })
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/admin'
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

    <form class="space-y-4" @submit.prevent="submit">
      <template v-if="showSchoolField">
        <BaseAlert v-if="tenantsError" variant="warning">
          {{ t('auth.schoolLoadError') }}
          <button type="button" class="ml-1 font-medium underline" @click="retryTenants">{{ t('common.retry') }}</button>
        </BaseAlert>
        <BaseSelect
          v-else
          v-model="school"
          :options="schoolOptions"
          :label="t('auth.school')"
          :placeholder="tenantsLoading ? t('common.loading') : t('auth.schoolPlaceholder')"
          :hint="t('auth.schoolHint')"
          :disabled="tenantsLoading"
          required
        />
      </template>

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
        <RouterLink to="/forgot-password" class="font-medium text-primary-600 hover:text-primary-700">
          {{ t('auth.login.forgotPassword') }}
        </RouterLink>
      </div>

      <BaseButton type="submit" :loading="submitting" block>{{ t('auth.login.submit') }}</BaseButton>
    </form>
  </div>
</template>

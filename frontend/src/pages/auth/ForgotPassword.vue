<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import { useTenantOptions } from '@/composables/useTenantOptions'
import { authService } from '@/services/auth'
import { useSiteStore } from '@/stores/site'

const { t } = useI18n()
const site = useSiteStore()
const route = useRoute()

const showSchoolField = computed(() => site.loaded && !site.resolved)
const { options: tenantOptions, loading: tenantsLoading, error: tenantsError, retry: retryTenants } = useTenantOptions()
const schoolOptions = computed(() => tenantOptions.value.map((t) => ({ value: t.slug, label: t.name })))

const school = ref(typeof route.query.tenant === 'string' ? route.query.tenant : '')

watch(tenantOptions, (opts) => {
  if (!school.value && opts.length === 1) school.value = opts[0]!.slug
})

const email = ref('')
const submitting = ref(false)
const sent = ref(false)

async function submit() {
  submitting.value = true
  try {
    await authService.forgotPassword(email.value, school.value.trim() || undefined)
  } finally {
    // Always shown, regardless of outcome — the backend intentionally
    // answers identically whether or not the address exists, so the
    // frontend must not distinguish either.
    sent.value = true
    submitting.value = false
  }
}
</script>

<template>
  <div>
    <h2 class="mb-1 text-lg font-semibold text-neutral-900">{{ t('auth.forgotPassword.title') }}</h2>
    <p class="mb-6 text-sm text-neutral-500">{{ t('auth.forgotPassword.subtitle') }}</p>

    <BaseAlert v-if="sent" variant="success">
      {{ t('auth.forgotPassword.sent') }}
    </BaseAlert>

    <form v-else class="space-y-4" @submit.prevent="submit">
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
      <BaseInput v-model="email" type="email" :label="t('auth.forgotPassword.email')" required autocomplete="username" />
      <BaseButton type="submit" :loading="submitting" block>{{ t('auth.forgotPassword.submit') }}</BaseButton>
    </form>

    <RouterLink to="/login" class="mt-6 block text-center text-sm font-medium text-secondary-600 hover:text-secondary-700">
      {{ t('auth.forgotPassword.backToSignIn') }}
    </RouterLink>
  </div>
</template>

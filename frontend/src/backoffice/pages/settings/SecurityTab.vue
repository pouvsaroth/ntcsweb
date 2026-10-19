<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

import { securityApi, type SecuritySettings } from '../../api'

/**
 * Settings → Security (as SmartGPS-V3's back office): whether staff sign in
 * with a code from an authenticator app as well as their password.
 *
 * Switching the code ON saves at once. Switching it OFF asks first: a staff
 * password alone then opens every school. The switch always shows what the
 * server answered, never just what was clicked.
 */
const { t } = useI18n()

const settings = ref<SecuritySettings | null>(null)
const loadFailed = ref(false)
const busy = ref(false)
const asking = ref(false)
const outcome = ref<{ ok: boolean; key: string } | null>(null)

async function load(): Promise<void> {
  loadFailed.value = false
  try {
    settings.value = await securityApi.get()
  } catch {
    loadFailed.value = true
  }
}

function flip(): void {
  if (!settings.value || busy.value) return
  if (settings.value.require_two_factor) asking.value = true
  else void save(true)
}

async function save(requireTwoFactor: boolean): Promise<void> {
  asking.value = false
  busy.value = true
  outcome.value = null
  try {
    settings.value = await securityApi.update({ require_two_factor: requireTwoFactor })
    outcome.value = { ok: true, key: settings.value.require_two_factor ? 'savedOn' : 'savedOff' }
  } catch {
    outcome.value = { ok: false, key: 'saveFailed' }
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <BaseAlert v-if="outcome" :variant="outcome.ok ? 'success' : 'danger'">{{ t(`bo.settings.security.${outcome.key}`) }}</BaseAlert>

    <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-5 shadow-[--shadow-card]">
      <h2 class="font-semibold text-neutral-900">{{ t('bo.settings.security.title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('bo.settings.security.sub') }}</p>

      <div v-if="loadFailed" class="mt-4 flex items-center gap-3 text-sm text-neutral-600">
        {{ t('bo.settings.security.loadFailed') }}
        <BaseButton size="sm" variant="outline" @click="load">{{ t('bo.settings.security.retry') }}</BaseButton>
      </div>
      <div v-else-if="!settings" class="flex justify-center py-6"><BaseSpinner /></div>

      <div v-else class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-neutral-100 pt-4">
        <div class="min-w-0 flex-1 basis-64">
          <p id="bo-sec-mfa" class="text-sm font-medium text-neutral-900">{{ t('bo.settings.security.label') }}</p>
          <p class="mt-0.5 text-sm text-neutral-500">
            {{ settings.require_two_factor ? t('bo.settings.security.onHelp') : t('bo.settings.security.offHelp') }}
          </p>
        </div>
        <span
          class="rounded-full px-2 py-0.5 text-xs font-medium"
          :class="settings.require_two_factor ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
        >
          {{ settings.require_two_factor ? t('bo.settings.security.required') : t('bo.settings.security.notRequired') }}
        </span>
        <button
          type="button"
          role="switch"
          aria-labelledby="bo-sec-mfa"
          :aria-checked="settings.require_two_factor"
          :disabled="busy"
          class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-60"
          :class="settings.require_two_factor ? 'bg-primary-500' : 'bg-neutral-300'"
          @click="flip"
        >
          <span
            class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform"
            :class="settings.require_two_factor ? 'translate-x-5' : 'translate-x-0.5'"
          />
        </button>
      </div>
    </section>

    <BaseModal v-model="asking" :title="t('bo.settings.security.confirmTitle')" size="sm">
      <p class="text-sm text-neutral-600">{{ t('bo.settings.security.confirmText') }}</p>
      <template #footer>
        <BaseButton variant="ghost" @click="asking = false">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton variant="danger" :loading="busy" @click="save(false)">{{ t('bo.settings.security.confirmOk') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>

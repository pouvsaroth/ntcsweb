<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
const { t } = useI18n()

// A direct link to /login never passes through PublicLayout (which is what
// normally triggers this), so without this call `site.resolved` would stay
// permanently false and the login form would always show its "School" field
// even on a real tenant subdomain where it isn't needed.
onMounted(() => site.load())
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 py-12">
    <div class="w-full max-w-sm">
      <div class="mb-4 flex justify-end">
        <LanguageSwitcher />
      </div>

      <div class="mb-8 flex flex-col items-center gap-2 text-center">
        <!-- A specific school is known (a real tenant subdomain/custom
             domain) — show its own branding, same as always. -->
        <template v-if="site.resolved">
          <img v-if="site.info.logo" :src="site.info.logo" alt="" class="h-16 w-16 rounded-xl object-contain" />
          <span v-else class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary-600 text-lg font-bold text-secondary-900">
            {{ site.info.name.charAt(0) }}
          </span>
          <h1 class="text-xl font-bold text-neutral-900">{{ site.info.name }}</h1>
          <p v-if="site.info.name_en" class="text-sm text-neutral-500">{{ site.info.name_en }}</p>
        </template>

        <!-- The shared ERP domain (or any other central domain) has no one
             school to brand this page with — show the platform's own mark
             instead of a blank/generic placeholder. -->
        <template v-else>
          <img src="/favicon.svg" alt="" class="h-16 w-16" />
          <h1 class="text-xl font-bold text-neutral-900">NTCSWEB</h1>
          <p class="text-sm text-neutral-500">{{ t('auth.platformTagline') }}</p>
        </template>
      </div>

      <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-6 shadow-[--shadow-card] sm:p-8">
        <RouterView />
      </div>
    </div>
  </div>
</template>

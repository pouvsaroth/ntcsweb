<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { schoolSetupTabs } from '@/router/schoolSetupTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * Academics > School setup (docs/academics/school-setup.md) — one sidebar
 * link, each section a tab rendered into the <RouterView> below as a child
 * route (see router/admin.ts), same as Attendance & Time.
 */
const { t } = useI18n()
const auth = useAuthStore()

const tabs = computed(() => schoolSetupTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('schoolSetup.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('schoolSetup.subtitle') }}</p>
    </div>

    <RouteTabBar :tabs="tabs" />

    <RouterView />
  </div>
</template>

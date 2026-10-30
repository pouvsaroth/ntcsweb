<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { canOpenProfessionalDevelopmentTab, professionalDevelopmentTabs } from '@/router/professionalDevelopmentTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * HRM > Professional Development (docs/hrm/professional-development.md) —
 * one sidebar link, each section a tab rendered into the <RouterView> below.
 */
const { t } = useI18n()
const auth = useAuthStore()

const tabs = computed(() => professionalDevelopmentTabs.filter((tab) => canOpenProfessionalDevelopmentTab(tab, auth.can)))
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('proDev.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('proDev.subtitle') }}</p>
    </div>

    <RouteTabBar :tabs="tabs" />

    <RouterView :key="$route.path" />
  </div>
</template>

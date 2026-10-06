<script setup lang="ts">
import { computed } from 'vue'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { recruitmentTabs } from '@/router/recruitmentTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * HRM > Recruitment — one sidebar link, each section a tab rendered into
 * the <RouterView> below as a child route (see router/admin.ts), same as
 * Organization Management.
 */
const auth = useAuthStore()

const tabs = computed(() => recruitmentTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <RouteTabBar :tabs="tabs" />

    <RouterView />
  </div>
</template>

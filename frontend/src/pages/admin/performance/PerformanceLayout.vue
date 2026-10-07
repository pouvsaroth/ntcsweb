<script setup lang="ts">
import { computed } from 'vue'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { performanceTabs } from '@/router/performanceTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * HRM > Performance Management — one sidebar link, each section a tab
 * rendered into the <RouterView> below as a child route (see
 * router/admin.ts), same as Payroll.
 */
const auth = useAuthStore()

const tabs = computed(() => performanceTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <RouteTabBar :tabs="tabs" />

    <RouterView :key="$route.path" />
  </div>
</template>

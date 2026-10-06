<script setup lang="ts">
import { computed } from 'vue'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { leaveTabs } from '@/router/leaveTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * HRM > Leave Management — one sidebar link, each section a tab rendered
 * into the <RouterView> below as a child route (see router/admin.ts), same
 * as Attendance & Time.
 */
const auth = useAuthStore()

const tabs = computed(() => leaveTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <RouteTabBar :tabs="tabs" />

    <RouterView />
  </div>
</template>

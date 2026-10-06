<script setup lang="ts">
import { computed } from 'vue'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { attendanceTabs } from '@/router/attendanceTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * HRM > Attendance & Time — one sidebar link, each section a tab rendered
 * into the <RouterView> below as a child route (see router/admin.ts), same
 * as Recruitment.
 */
const auth = useAuthStore()

const tabs = computed(() => attendanceTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <RouteTabBar :tabs="tabs" />

    <RouterView />
  </div>
</template>

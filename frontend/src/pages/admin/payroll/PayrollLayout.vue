<script setup lang="ts">
import { computed } from 'vue'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { payrollTabs } from '@/router/payrollTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * HRM > Payroll — one sidebar link, each section a tab rendered into the
 * <RouterView> below as a child route (see router/admin.ts), same as Leave
 * Management.
 */
const auth = useAuthStore()

const tabs = computed(() => payrollTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <RouteTabBar :tabs="tabs" />

    <!-- Keyed: Allowances, Bonuses and Deductions are one page (PayComponents.vue), so it must remount between them. -->
    <RouterView :key="$route.path" />
  </div>
</template>

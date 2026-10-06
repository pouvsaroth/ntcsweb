<script setup lang="ts">
import { computed } from 'vue'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { canOpenOrganizationTab, organizationTabs } from '@/router/organizationTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * HRM > Organization Management — the sidebar has a single link to it (see
 * adminNav.ts), and each section is a tab here, rendered into the
 * <RouterView> below as a child route (see router/admin.ts). The tab pages
 * themselves (SchoolSettings, Departments, Positions, OrganizationUnits) know nothing about
 * this bar, so Departments can still open on its own from the Assets menu.
 */
const auth = useAuthStore()

const tabs = computed(() => organizationTabs.filter((tab) => canOpenOrganizationTab(tab, auth.can)))
</script>

<template>
  <div>
    <RouteTabBar :tabs="tabs" />

    <RouterView />
  </div>
</template>

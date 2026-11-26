<script setup lang="ts">
import { computed } from 'vue'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { fundReportTabs } from '@/router/fundReportTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * Fund > Reports — one sidebar link; Summary, Money in & out and Providers
 * by month are tabs rendered into the <RouterView> below as child routes
 * (see router/admin.ts). The tab bar is left out of prints.
 */
const auth = useAuthStore()

const tabs = computed(() => fundReportTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <div class="print:hidden">
      <RouteTabBar :tabs="tabs" />
    </div>

    <RouterView />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { marketingTabs } from '@/router/marketingTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * Marketing — one sidebar link, each section a tab rendered into the
 * <RouterView> below as a child route (see router/admin.ts).
 */
const auth = useAuthStore()

const tabs = computed(() => marketingTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <RouteTabBar :tabs="tabs" />

    <RouterView :key="$route.path" />
  </div>
</template>

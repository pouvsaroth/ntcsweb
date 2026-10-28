<script setup lang="ts">
import { computed } from 'vue'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { frontOfficeTabs } from '@/router/frontOfficeTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * Front Office — one sidebar link, each section a tab rendered into the
 * <RouterView> below as a child route (see router/admin.ts).
 */
const auth = useAuthStore()

const tabs = computed(() => frontOfficeTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <RouteTabBar :tabs="tabs" />

    <RouterView :key="$route.path" />
  </div>
</template>

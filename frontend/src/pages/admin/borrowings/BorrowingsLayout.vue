<script setup lang="ts">
import { computed } from 'vue'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { borrowingsTabs } from '@/router/borrowingsTabs'
import { useAuthStore } from '@/stores/auth'

/** Accounting > Borrowings — one sidebar link, each section a tab (child route). */
const auth = useAuthStore()

const tabs = computed(() => borrowingsTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <RouteTabBar :tabs="tabs" />
    <RouterView :key="$route.path" />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { expenseTabs } from '@/router/expenseTabs'
import { useAuthStore } from '@/stores/auth'

/** Accounting > Expenses — one sidebar link: the expenses list and Expense for as tabs (child routes). */
const auth = useAuthStore()

const tabs = computed(() => expenseTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <RouteTabBar :tabs="tabs" />
    <RouterView :key="$route.path" />
  </div>
</template>

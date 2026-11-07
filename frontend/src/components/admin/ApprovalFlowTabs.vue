<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/** Shared tab bar for Approval Flow: Groups and Flow Setting — mirrors AttendanceTabs.vue. */
const { t } = useI18n()
const route = useRoute()

const auth = useAuthStore()

// Each tab has its own View permission (approval-groups.view, approval-flows.view).
const tabs = [
  { to: '/admin/approval-flow/groups', labelKey: 'adminNav.items.approvalGroups', permission: 'approval-groups.view' },
  { to: '/admin/approval-flow/settings', labelKey: 'adminNav.items.flowSetting', permission: 'approval-flows.view' },
].filter((tab) => auth.can(tab.permission))
</script>

<template>
  <div class="mb-6 border-b border-neutral-200">
    <nav class="-mb-px flex gap-x-6 overflow-x-auto [scrollbar-width:none] sm:flex-wrap sm:gap-y-1 sm:overflow-visible [&::-webkit-scrollbar]:hidden">
      <RouterLink
        v-for="tab in tabs"
        :key="tab.to"
        :to="tab.to"
        class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium"
        :class="
          route.path === tab.to
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700'
        "
      >
        {{ t(tab.labelKey) }}
      </RouterLink>
    </nav>
  </div>
</template>

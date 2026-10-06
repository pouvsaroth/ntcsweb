<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { canOpenOrganizationTab, organizationTabs } from '@/router/organizationTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * HRM > Organization Management — the sidebar has a single link to it (see
 * adminNav.ts), and each section is a tab here, rendered into the
 * <RouterView> below as a child route (see router/admin.ts). The tab pages
 * themselves (SchoolSettings, Departments, Positions, OrganizationUnits) know nothing about
 * this bar, so Departments can still open on its own from the Assets menu.
 */
const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()

const tabs = computed(() => organizationTabs.filter((tab) => canOpenOrganizationTab(tab, auth.can)))
</script>

<template>
  <div>
    <div class="mb-6 border-b border-neutral-200">
      <nav class="-mb-px flex flex-wrap gap-x-6 gap-y-1">
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

    <RouterView />
  </div>
</template>

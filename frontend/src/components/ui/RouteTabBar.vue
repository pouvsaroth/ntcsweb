<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * The underlined tab bar of a page whose tabs are child routes (HRM >
 * Organization Management, Settings > Language, ...). Callers pass only the
 * tabs this account may open.
 */
const props = defineProps<{ tabs: { to: string; labelKey: string }[] }>()

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()

// Tabs a role hid in Settings > Roles never show (see router/tabVisibility.ts).
const shownTabs = computed(() => props.tabs.filter((tab) => !auth.tabHidden(tab.to)))

// The tab whose path is the current page or the closest parent of it, so a
// detail page under a tab (Help articles > one article) keeps its tab lit.
const activeTo = computed(() =>
  shownTabs.value
    .map((tab) => tab.to)
    .filter((to) => route.path === to || route.path.startsWith(to + '/'))
    .sort((a, b) => b.length - a.length)[0],
)
</script>

<template>
  <div class="mb-6 border-b border-neutral-200">
    <nav class="-mb-px flex gap-x-6 overflow-x-auto [scrollbar-width:none] sm:flex-wrap sm:gap-y-1 sm:overflow-visible [&::-webkit-scrollbar]:hidden">
      <RouterLink
        v-for="tab in shownTabs"
        :key="tab.to"
        :to="tab.to"
        class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium"
        :class="
          activeTo === tab.to
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700'
        "
      >
        {{ t(tab.labelKey) }}
      </RouterLink>
    </nav>
  </div>
</template>

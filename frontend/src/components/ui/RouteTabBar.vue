<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * The underlined tab bar of a page whose tabs are child routes (HRM >
 * Organization Management, Settings > Language, ...). Callers pass only the
 * tabs this account may open.
 *
 * With `groups` (and a `group` on each tab), phones show the groups as a
 * row of buttons and only the open group's tabs under them; from sm up the
 * bar is unchanged.
 */
const props = defineProps<{
  tabs: { to: string; labelKey: string; group?: string }[]
  groups?: { key: string; labelKey: string }[]
}>()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

// Tabs a role hid in Settings > Roles never show (see router/tabVisibility.ts); the menu
// layout (router/menuLayout.ts) orders them and can take one out as a menu item.
const shownTabs = computed(() => auth.orderTabs(props.tabs).filter((tab) => auth.tabInBar(tab.to)))

// The tab whose path is the current page or the closest parent of it, so a
// detail page under a tab (Help articles > one article) keeps its tab lit.
const activeTo = computed(() =>
  shownTabs.value
    .map((tab) => tab.to)
    .filter((to) => route.path === to || route.path.startsWith(to + '/'))
    .sort((a, b) => b.length - a.length)[0],
)

// Phone groups: only those with a tab this account can see; the open one is the current tab's.
const shownGroups = computed(() => (props.groups ?? []).filter((group) => shownTabs.value.some((tab) => tab.group === group.key)))
const activeGroup = computed(() => shownTabs.value.find((tab) => tab.to === activeTo.value)?.group ?? shownGroups.value[0]?.key)
const grouped = computed(() => shownGroups.value.length > 1)
function openGroup(key: string) {
  const first = shownTabs.value.find((tab) => tab.group === key)
  if (first && key !== activeGroup.value) router.push(first.to)
}
</script>

<template>
  <div v-if="grouped" class="mb-3 flex gap-1 rounded-lg bg-neutral-100 p-1 sm:hidden" role="tablist">
    <button
      v-for="group in shownGroups"
      :key="group.key"
      type="button"
      role="tab"
      :aria-selected="activeGroup === group.key"
      class="flex-1 rounded-md px-2 py-1.5 text-sm font-medium"
      :class="activeGroup === group.key ? 'bg-white text-primary-700 shadow-sm' : 'text-neutral-600'"
      @click="openGroup(group.key)"
    >
      {{ t(group.labelKey) }}
    </button>
  </div>
  <div class="mb-6 border-b border-neutral-200">
    <nav class="-mb-px flex gap-x-6 overflow-x-auto [scrollbar-width:none] sm:flex-wrap sm:gap-y-1 sm:overflow-visible [&::-webkit-scrollbar]:hidden">
      <RouterLink
        v-for="tab in shownTabs"
        :key="tab.to"
        :to="tab.to"
        class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium"
        :class="[
          activeTo === tab.to
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700',
          grouped && tab.group !== activeGroup ? 'max-sm:hidden' : '',
        ]"
      >
        {{ t(tab.labelKey) }}
      </RouterLink>
    </nav>
  </div>
</template>

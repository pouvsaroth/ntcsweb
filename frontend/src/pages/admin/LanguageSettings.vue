<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Settings > Language: the language list and the school's own wording of
 * the app's text, as tabs (child routes, see router/admin.ts). Each tab has
 * its own permission, and only the ones this account holds are shown.
 */
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const allTabs = [
  { to: '/admin/languages', labelKey: 'admin.languages.tabLanguage', permission: 'base-data.manage-languages' },
  { to: '/admin/languages/translations', labelKey: 'admin.languages.tabTranslation', permission: 'base-data.manage-translations' },
]

const tabs = computed(() => allTabs.filter((tab) => auth.can(tab.permission)))

// The sidebar link opens the Language tab — someone allowed to translate but
// not to manage languages goes straight on to the tab they can use.
watch(
  () => route.path,
  (path) => {
    const current = allTabs.find((tab) => tab.to === path)
    if (current && !auth.can(current.permission) && tabs.value.length > 0) void router.replace(tabs.value[0]!.to)
  },
  { immediate: true },
)
</script>

<template>
  <div>
    <RouteTabBar :tabs="tabs" />

    <RouterView />
  </div>
</template>

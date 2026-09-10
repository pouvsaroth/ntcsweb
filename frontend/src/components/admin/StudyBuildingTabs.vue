<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

/**
 * Shared tab bar across the "Study Building" pages — Buildings and
 * Classrooms used to be separate sidebar entries; they're now reached
 * through one "Study Building" item, with this tab bar as the switcher
 * between them. Each tab is a real route (not client-side state), so both
 * pages keep their own independent list/CRUD logic completely untouched —
 * this component only renders the bar itself. Mirrors ProgramsTabs.vue.
 */
const { t } = useI18n()
const route = useRoute()

const tabs = [
  { to: '/admin/buildings', labelKey: 'adminNav.items.buildings' },
  { to: '/admin/classrooms', labelKey: 'adminNav.items.classrooms' },
]
</script>

<template>
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
</template>

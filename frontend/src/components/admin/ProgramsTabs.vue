<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * Shared tab bar across these "Programs" pages — these used to be separate
 * sidebar entries under Academic; they're now reached through one "Programs"
 * item, with this tab bar as the switcher between them. Each tab is a real
 * route (not client-side state), so every page keeps its own independent
 * list/CRUD logic completely untouched — this component only renders the
 * bar itself.
 *
 * Study Mode isn't a tab here — it moved into the generic base-data lookup
 * system (see BaseDataSeeder's STUDY_MODE category), managed like any other
 * lookup category under Settings > Base Data instead of its own page.
 */
const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()

// Only the tabs this user (and the school's plan) allows; no tab leads to "unauthorized".
const tabs = computed(() =>
  [
    { to: '/admin/academic-programs', labelKey: 'adminNav.items.academicPrograms', permission: 'academic-programs.view' },
    { to: '/admin/academic-years', labelKey: 'adminNav.items.academicYears', permission: 'academic-years.view' },
    { to: '/admin/course-packages', labelKey: 'adminNav.items.coursePackages', permission: 'course-packages.view' },
    { to: '/admin/books', labelKey: 'adminNav.items.books', permission: 'books.view' },
    { to: '/admin/book-categories', labelKey: 'adminNav.items.bookCategories', permission: 'book-categories.view' },
  ].filter((tab) => auth.can(tab.permission)),
)
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

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue'
import { adminNav } from '@/router/adminNav'

defineEmits<{ 'toggle-sidebar': [] }>()

const route = useRoute()
const { t } = useI18n()

/** The current section's name (e.g. "Students", "Books") — same key each page's sidebar link and <h1> already use, so this always matches whatever menu you're on. */
const pageTitle = computed(() => (route.meta.titleKey ? t(String(route.meta.titleKey)) : null))

/** A sub-page (e.g. "Register Student") sets this to render "Students › Register Student" here instead of duplicating "Students" again in its own page body. */
const subPageTitle = computed(() => (route.meta.pageTitleKey ? t(String(route.meta.pageTitleKey)) : null))

/** Where the "Students" part of that breadcrumb links back to — the same sidebar item's own `to`, found by its label key, so this works for any future sub-page without hardcoding a route name here. */
const sectionLink = computed(() => {
  const labelKey = route.meta.titleKey ? String(route.meta.titleKey) : null
  if (!labelKey) return null

  for (const group of adminNav) {
    const item = group.items.find((entry) => entry.labelKey === labelKey)
    if (item) return item.to
  }

  return null
})
</script>

<template>
  <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-neutral-200 bg-white px-4 sm:px-6">
    <div class="flex items-center gap-3">
      <button
        type="button"
        class="rounded-lg p-2 text-neutral-600 hover:bg-neutral-100 lg:hidden"
        :aria-label="t('common.toggleSidebar')"
        @click="$emit('toggle-sidebar')"
      >
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
      </button>
      <p v-if="pageTitle" class="hidden items-center gap-2 text-sm font-medium text-neutral-500 sm:flex">
        <template v-if="subPageTitle">
          <RouterLink v-if="sectionLink" :to="sectionLink" class="hover:text-primary-700">{{ pageTitle }}</RouterLink>
          <span v-else>{{ pageTitle }}</span>
          <span class="text-neutral-300">›</span>
          <span class="text-neutral-900">{{ subPageTitle }}</span>
        </template>
        <template v-else>{{ pageTitle }}</template>
      </p>
    </div>

    <!-- The account menu (picture, name, email/phone, Edit Profile, Change
         Password, Sign out) lives on the sidebar's profile card now — see
         AdminSidebar.vue. -->
    <LanguageSwitcher />
  </header>
</template>

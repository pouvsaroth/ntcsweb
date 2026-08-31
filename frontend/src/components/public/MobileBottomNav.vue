<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

const { t } = useI18n()

const items = [
  { labelKey: 'mobileNav.home', to: '/', icon: 'home' },
  { labelKey: 'mobileNav.programsFee', to: '/programs', icon: 'programs' },
  { labelKey: 'mobileNav.register', to: '/register', icon: 'register' },
  { labelKey: 'mobileNav.schedule', to: '/schedule', icon: 'schedule' },
  { labelKey: 'mobileNav.photo', to: '/gallery', icon: 'photo' },
] as const
</script>

<template>
  <!-- Mobile-only bottom tab bar — the desktop/tablet nav lives in
       PublicHeader. `pb-[env(safe-area-inset-bottom)]` clears the home
       indicator on notched phones instead of sitting under it. -->
  <nav
    class="fixed inset-x-0 bottom-0 z-40 border-t border-neutral-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden"
    :aria-label="t('common.primaryNav')"
  >
    <div class="grid grid-cols-5">
      <RouterLink
        v-for="item in items"
        :key="item.to"
        :to="item.to"
        class="flex flex-col items-center gap-1 py-2 text-neutral-500"
        exact-active-class="text-primary-700"
      >
        <svg v-if="item.icon === 'home'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4a1 1 0 001-1v-4a1 1 0 011-1h0a1 1 0 011 1v4a1 1 0 001 1h4a1 1 0 001-1V10" />
        </svg>
        <svg v-else-if="item.icon === 'programs'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
        </svg>
        <svg v-else-if="item.icon === 'register'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3M13 7a4 4 0 11-8 0 4 4 0 018 0zM1 21v-2a4 4 0 014-4h4a4 4 0 014 4v2" />
        </svg>
        <svg v-else-if="item.icon === 'schedule'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M14 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <span class="text-center text-[10px] font-medium leading-tight">{{ t(item.labelKey) }}</span>
      </RouterLink>
    </div>
  </nav>
</template>

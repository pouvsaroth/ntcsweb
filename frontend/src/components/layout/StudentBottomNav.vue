<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

const { t } = useI18n()
</script>

<template>
  <!-- A student's mobile-only quick-access bar — just a single Home button
       back to Dashboard.vue's student card grid (Score/Attendant/Video/...
       all live there now, see admin.ts's `studentAllowed` dashboard route).
       Profile is still reachable from AdminSidebar's own profile card. -->
  <nav
    class="fixed inset-x-0 bottom-0 z-40 border-t border-neutral-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden"
    :aria-label="t('common.primaryNav')"
  >
    <div class="flex justify-center">
      <RouterLink to="/admin" class="flex flex-col items-center gap-1 py-2 text-primary-500" exact-active-class="text-primary-700">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
          />
        </svg>
        <span class="text-center text-[10px] font-medium leading-tight">{{ t('studentNav.home') }}</span>
      </RouterLink>
    </div>
  </nav>
</template>

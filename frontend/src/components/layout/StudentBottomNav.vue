<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import EditProfileModal from '@/components/layout/EditProfileModal.vue'

const { t } = useI18n()
const editProfileOpen = ref(false)
</script>

<template>
  <!-- A student's mobile-only quick-access bar — Score/Video are still
       placeholders (ComingSoon.vue); Attendant links to the real
       MyAttendance.vue. Profile reuses the same EditProfileModal every
       other account menu opens. -->
  <nav
    class="fixed inset-x-0 bottom-0 z-40 border-t border-neutral-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden"
    :aria-label="t('common.primaryNav')"
  >
    <div class="grid grid-cols-5">
      <RouterLink to="/" class="flex flex-col items-center gap-1 py-2 text-neutral-500">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span class="text-center text-[10px] font-medium leading-tight">{{ t('studentNav.website') }}</span>
      </RouterLink>
      <RouterLink to="/admin/my-scores" class="flex flex-col items-center gap-1 py-2 text-neutral-500" exact-active-class="text-primary-700">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
        </svg>
        <span class="text-center text-[10px] font-medium leading-tight">{{ t('studentNav.score') }}</span>
      </RouterLink>
      <RouterLink to="/admin/my-attendance" class="flex flex-col items-center gap-1 py-2 text-neutral-500" exact-active-class="text-primary-700">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span class="text-center text-[10px] font-medium leading-tight">{{ t('studentNav.attendant') }}</span>
      </RouterLink>
      <RouterLink to="/admin/my-videos" class="flex flex-col items-center gap-1 py-2 text-neutral-500" exact-active-class="text-primary-700">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
        </svg>
        <span class="text-center text-[10px] font-medium leading-tight">{{ t('studentNav.video') }}</span>
      </RouterLink>
      <button type="button" class="flex flex-col items-center gap-1 py-2 text-neutral-500" @click="editProfileOpen = true">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
        </svg>
        <span class="text-center text-[10px] font-medium leading-tight">{{ t('studentNav.profile') }}</span>
      </button>
    </div>
  </nav>

  <EditProfileModal v-model="editProfileOpen" />
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import PublicUserMenu from '@/components/layout/PublicUserMenu.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue'
import { programNavItem, publicNavAfterProgram, publicNavBeforeProgram } from '@/router/publicNav'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
const auth = useAuthStore()
const { t } = useI18n()

// One flat list — Program used to open a dropdown of separate
// destinations; it's now a plain link like every other item here (see
// publicNav.ts's programNavItem docblock).
const mainNavItems = [...publicNavBeforeProgram, programNavItem, ...publicNavAfterProgram]
</script>

<template>
  <header class="sticky top-0 z-40 border-b border-neutral-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
      <!-- Desktop only — on mobile/tablet this slot is a profile/login
           icon instead (see below), so the whole brand block hides there. -->
      <RouterLink to="/" class="hidden items-center gap-2 font-bold text-secondary-700 lg:flex">
        <img v-if="site.info.logo" :src="site.info.logo" alt="" class="h-9 w-9 rounded object-contain" />
        <span v-else class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-600 text-secondary-900">
          {{ site.info.name.charAt(0) }}
        </span>
        <span class="text-lg">{{ site.info.name }}</span>
      </RouterLink>

      <!-- Mobile/tablet only — replaces the logo with a profile/login
           control in the same top-left slot. -->
      <div class="flex items-center lg:hidden">
        <PublicUserMenu v-if="auth.isAuthenticated" compact align="left" />
        <BaseButton v-else href="/login" size="sm">{{ t('nav.signIn') }}</BaseButton>
      </div>

      <!-- Desktop nav -->
      <nav class="hidden items-center gap-1 lg:flex" :aria-label="t('common.primaryNav')">
        <RouterLink
          v-for="item in mainNavItems"
          :key="item.to"
          :to="item.to"
          class="rounded-lg px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
          active-class="text-primary-800 bg-primary-50"
        >
          {{ t(item.labelKey) }}
        </RouterLink>
      </nav>

      <div class="hidden items-center gap-2 lg:flex">
        <template v-if="auth.isAuthenticated">
          <PublicUserMenu />
        </template>
        <template v-else>
          <BaseButton to="/register" variant="outline" size="sm">{{ t('nav.register') }}</BaseButton>
          <BaseButton href="/login" size="sm">{{ t('nav.portalLogin') }}</BaseButton>
        </template>
        <LanguageSwitcher />
      </div>

      <!-- Mobile/tablet controls — no hamburger: the bottom tab bar
           (MobileBottomNav) is the mobile site's primary navigation. -->
      <div class="flex items-center gap-1 lg:hidden">
        <LanguageSwitcher />
      </div>
    </div>
  </header>
</template>

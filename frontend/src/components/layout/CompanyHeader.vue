<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BaseButton from '@/components/ui/BaseButton.vue'
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue'
import { useSiteStore } from '@/stores/site'

/**
 * The header of the services front page and the School Management System
 * page: the brand back to the services page, the language, and the way in
 * to the system. No school menu — that belongs to /computer-school.
 */
const site = useSiteStore()
const { t } = useI18n()
</script>

<template>
  <header class="sticky top-0 z-40 border-b border-neutral-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
      <RouterLink to="/" class="flex min-w-0 items-center gap-2 font-bold text-secondary-700">
        <img v-if="site.info.logo" :src="site.info.logo" alt="" class="h-9 w-9 shrink-0 rounded object-contain" />
        <span v-else class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-600 text-secondary-900">
          {{ site.info.name.charAt(0) }}
        </span>
        <span class="truncate text-base sm:text-lg">{{ site.info.name }}</span>
      </RouterLink>
      <div class="flex shrink-0 items-center gap-2">
        <BaseButton href="/login" size="sm" class="hidden sm:inline-flex">{{ t('nav.portalLogin') }}</BaseButton>
        <LanguageSwitcher />
      </div>
    </div>
  </header>
</template>

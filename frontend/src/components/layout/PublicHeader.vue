<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BaseButton from '@/components/ui/BaseButton.vue'
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue'
import { publicNav } from '@/router/publicNav'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
const { t } = useI18n()
const mobileOpen = ref(false)
</script>

<template>
  <header class="sticky top-0 z-40 border-b border-neutral-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
      <RouterLink to="/" class="flex items-center gap-2 font-bold text-secondary-700">
        <img v-if="site.info.logo" :src="site.info.logo" alt="" class="h-9 w-9 rounded object-contain" />
        <span v-else class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-600 text-secondary-900">
          {{ site.info.name.charAt(0) }}
        </span>
        <span class="hidden text-lg sm:inline">{{ site.info.name }}</span>
      </RouterLink>

      <!-- Desktop nav -->
      <nav class="hidden items-center gap-1 lg:flex" :aria-label="t('common.primaryNav')">
        <RouterLink
          v-for="item in publicNav"
          :key="item.to"
          :to="item.to"
          class="rounded-lg px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
          active-class="text-primary-800 bg-primary-50"
        >
          {{ t(item.labelKey) }}
        </RouterLink>
      </nav>

      <div class="hidden items-center gap-2 lg:flex">
        <LanguageSwitcher />
        <BaseButton href="/login" target="_blank" size="sm">{{ t('nav.portalLogin') }}</BaseButton>
      </div>

      <!-- Mobile menu toggle -->
      <div class="flex items-center gap-1 lg:hidden">
        <LanguageSwitcher />
        <button
          type="button"
          class="rounded-lg p-2 text-neutral-600 hover:bg-neutral-100"
          :aria-expanded="mobileOpen"
          :aria-label="t('common.toggleMenu')"
          @click="mobileOpen = !mobileOpen"
        >
          <svg v-if="!mobileOpen" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
          <svg v-else class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Mobile nav panel -->
    <Transition
      enter-active-class="transition ease-out duration-150"
      enter-from-class="opacity-0 -translate-y-2"
      enter-to-class="opacity-100 translate-y-0"
    >
      <div v-if="mobileOpen" class="border-t border-neutral-200 bg-white lg:hidden">
        <nav class="flex flex-col gap-1 px-4 py-3" :aria-label="t('common.primaryNav')">
          <RouterLink
            v-for="item in publicNav"
            :key="item.to"
            :to="item.to"
            class="rounded-lg px-3 py-2.5 text-sm font-medium text-neutral-600 hover:bg-neutral-100"
            active-class="text-primary-800 bg-primary-50"
            @click="mobileOpen = false"
          >
            {{ t(item.labelKey) }}
          </RouterLink>
          <BaseButton href="/login" target="_blank" class="mt-2" block>{{ t('nav.portalLogin') }}</BaseButton>
        </nav>
      </div>
    </Transition>
  </header>
</template>

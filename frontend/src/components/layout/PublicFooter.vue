<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { publicNav } from '@/router/publicNav'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
const { t } = useI18n()
const year = new Date().getFullYear()
</script>

<template>
  <footer class="bg-secondary-900 text-secondary-100">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <div class="flex items-center gap-2">
            <span
              v-if="!site.info.logo"
              class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-500 text-sm font-bold text-secondary-900"
            >
              {{ site.info.name.charAt(0) }}
            </span>
            <img v-else :src="site.info.logo" alt="" class="h-9 w-9 rounded object-contain" />
            <span class="font-bold text-white">{{ site.info.name }}</span>
          </div>
        </div>

        <div>
          <p class="text-sm font-semibold uppercase tracking-wide text-secondary-300">{{ t('footer.explore') }}</p>
          <ul class="mt-3 space-y-2">
            <li v-for="item in publicNav" :key="item.to">
              <RouterLink :to="item.to" class="text-sm hover:text-white">{{ t(item.labelKey) }}</RouterLink>
            </li>
          </ul>
        </div>

        <div>
          <p class="text-sm font-semibold uppercase tracking-wide text-secondary-300">{{ t('footer.resources') }}</p>
          <ul class="mt-3 space-y-2">
            <li><RouterLink to="/documents" class="text-sm hover:text-white">{{ t('nav.documents') }}</RouterLink></li>
            <li><RouterLink to="/announcements" class="text-sm hover:text-white">{{ t('nav.announcements') }}</RouterLink></li>
            <li><RouterLink to="/login" class="text-sm hover:text-white">{{ t('footer.login') }}</RouterLink></li>
          </ul>
        </div>

        <div>
          <p class="text-sm font-semibold uppercase tracking-wide text-secondary-300">{{ t('footer.contact') }}</p>
          <ul class="mt-3 space-y-2 text-sm">
            <li v-if="site.info.phone" class="flex items-center gap-2">
              <span aria-hidden="true">📞</span>{{ site.info.phone }}
            </li>
            <li v-if="site.info.email" class="flex items-center gap-2">
              <span aria-hidden="true">📧</span>{{ site.info.email }}
            </li>
            <li v-if="site.info.address" class="flex items-center gap-2">
              <span aria-hidden="true">📍</span>{{ site.info.address }}
            </li>
          </ul>
        </div>
      </div>

      <div class="mt-10 border-t border-secondary-700 pt-6 text-center text-sm text-secondary-300">
        &copy; {{ year }} {{ site.info.name }}. {{ t('footer.rightsReserved') }}
      </div>
    </div>
  </footer>
</template>

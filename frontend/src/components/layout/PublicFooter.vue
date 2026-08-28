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
  <footer class="border-t border-neutral-200 bg-neutral-900 text-neutral-300">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <p class="text-lg font-bold text-white">{{ site.info.name }}</p>
          <p v-if="site.info.address" class="mt-3 text-sm">{{ site.info.address }}</p>
          <p v-if="site.info.phone" class="mt-1 text-sm">{{ site.info.phone }}</p>
          <p v-if="site.info.email" class="mt-1 text-sm">{{ site.info.email }}</p>
        </div>

        <div>
          <p class="text-sm font-semibold uppercase tracking-wide text-neutral-400">{{ t('footer.explore') }}</p>
          <ul class="mt-3 space-y-2">
            <li v-for="item in publicNav" :key="item.to">
              <RouterLink :to="item.to" class="text-sm hover:text-white">{{ t(item.labelKey) }}</RouterLink>
            </li>
          </ul>
        </div>

        <div>
          <p class="text-sm font-semibold uppercase tracking-wide text-neutral-400">{{ t('footer.resources') }}</p>
          <ul class="mt-3 space-y-2">
            <li><RouterLink to="/documents" class="text-sm hover:text-white">{{ t('nav.documents') }}</RouterLink></li>
            <li><RouterLink to="/announcements" class="text-sm hover:text-white">{{ t('nav.announcements') }}</RouterLink></li>
            <li><RouterLink to="/students" class="text-sm hover:text-white">{{ t('nav.students') }}</RouterLink></li>
          </ul>
        </div>

        <div>
          <p class="text-sm font-semibold uppercase tracking-wide text-neutral-400">{{ t('footer.portal') }}</p>
          <ul class="mt-3 space-y-2">
            <li><RouterLink to="/login" class="text-sm hover:text-white">{{ t('footer.login') }}</RouterLink></li>
          </ul>
        </div>
      </div>

      <div class="mt-10 border-t border-neutral-800 pt-6 text-center text-sm text-neutral-500">
        &copy; {{ year }} {{ site.info.name }}. {{ t('footer.rightsReserved') }}
      </div>
    </div>
  </footer>
</template>

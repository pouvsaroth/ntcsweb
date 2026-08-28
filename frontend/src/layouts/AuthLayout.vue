<script setup lang="ts">
import { onMounted } from 'vue'

import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()

// A direct link to /login never passes through PublicLayout (which is what
// normally triggers this), so without this call `site.resolved` would stay
// permanently false and the login form would always show its "School" field
// even on a real tenant subdomain where it isn't needed.
onMounted(() => site.load())
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-neutral-50 px-4 py-12">
    <div class="w-full max-w-sm">
      <div class="mb-4 flex justify-end">
        <LanguageSwitcher />
      </div>

      <div class="mb-8 flex flex-col items-center gap-3 text-center">
        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary-600 text-lg font-bold text-white">
          {{ site.info.name.charAt(0) }}
        </span>
        <h1 class="text-xl font-bold text-neutral-900">{{ site.info.name }}</h1>
      </div>

      <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-6 shadow-[--shadow-card] sm:p-8">
        <RouterView />
      </div>
    </div>
  </div>
</template>

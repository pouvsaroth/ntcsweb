<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
const { locale } = useI18n()

// Only Khmer has its own translation here — every other supported locale
// (en, zh, ko, ja) falls back to the English name rather than adding
// half-finished translations for a fixed platform mark nobody asked for.
const brandName = computed(() => (locale.value === 'km' ? 'ប្រព័ន្ធគ្រប់គ្រងសាលា' : 'School Management System'))

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

      <div class="mb-8 flex flex-col items-center gap-2 text-center">
        <!-- This login page is shared by every school on the platform, so
             it always shows the platform's own fixed mark — never a
             tenant's logo/name, even once site.resolved is true (that flag
             still drives Login.vue's "School" field, just not this). -->
        <img src="/school-management-system-logo.png" alt="" class="h-32 w-32 object-contain" />
        <h1 class="text-xl font-bold text-neutral-900">{{ brandName }}</h1>
        <p class="text-xs text-neutral-400">flexi solutions co, ltd.</p>
      </div>

      <div class="rounded-[--radius-card] border border-neutral-200 bg-white p-6 shadow-[--shadow-card] sm:p-8">
        <RouterView />
      </div>
    </div>
  </div>
</template>

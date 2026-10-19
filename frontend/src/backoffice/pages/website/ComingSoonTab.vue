<script setup lang="ts">
import { useI18n } from 'vue-i18n'

/** News, Events, Announcements, Documents: on the menu, not built yet (as in the school app before). */
defineProps<{ tab: string }>()

const { t } = useI18n()
</script>

<template>
  <div class="rounded-[--radius-card] border border-dashed border-neutral-300 bg-white px-6 py-14 text-center">
    <p class="text-lg font-semibold text-neutral-900">{{ t(`bo.website.tabs.${tab}`) }}</p>
    <p class="mt-1 text-sm text-neutral-500">{{ t('bo.website.comingSoon') }}</p>
  </div>
</template>

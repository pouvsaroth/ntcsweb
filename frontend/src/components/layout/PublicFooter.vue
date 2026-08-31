<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
const { t } = useI18n()
const year = new Date().getFullYear()
</script>

<template>
  <footer class="bg-secondary-900 text-secondary-100">
    <div class="mx-auto max-w-7xl px-4 py-6 text-center text-sm text-secondary-300 sm:px-6 lg:px-8">
      &copy; {{ year }} {{ site.info.name }}. {{ t('footer.rightsReserved') }}
    </div>
  </footer>
</template>

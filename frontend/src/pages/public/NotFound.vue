<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import BaseButton from '@/components/ui/BaseButton.vue'

const { t } = useI18n()
</script>

<template>
  <div class="flex min-h-[60vh] flex-col items-center justify-center px-4 text-center">
    <p class="text-6xl font-bold text-secondary-600">404</p>
    <h1 class="mt-2 text-xl font-semibold text-neutral-900">{{ t('notFound.title') }}</h1>
    <p class="mt-2 text-neutral-500">{{ t('notFound.message') }}</p>
    <BaseButton to="/" class="mt-6">{{ t('notFound.backHome') }}</BaseButton>
  </div>
</template>

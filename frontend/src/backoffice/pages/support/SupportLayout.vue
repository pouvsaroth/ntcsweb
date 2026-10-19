<script setup lang="ts">
import { useI18n } from 'vue-i18n'

/** Support: every support view, and how long one lasts. */
const { t } = useI18n()

const tabs = [
  { to: '/support/views', key: 'views' },
  { to: '/support/settings', key: 'settings' },
] as const
</script>

<template>
  <div>
    <h1 class="mb-4 text-xl font-semibold text-neutral-900">{{ t('bo.support.title') }}</h1>
    <nav class="mb-6 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-neutral-200">
      <RouterLink
        v-for="tab in tabs"
        :key="tab.to"
        :to="tab.to"
        class="-mb-px border-b-2 border-transparent px-3 py-2 text-sm font-medium whitespace-nowrap text-neutral-500 hover:text-neutral-800"
        active-class="!border-primary-500 !text-neutral-900"
      >
        {{ t(`bo.support.tabs.${tab.key}`) }}
      </RouterLink>
    </nav>
    <RouterView />
  </div>
</template>

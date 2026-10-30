<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { useHelpCenter } from '@/composables/useHelpCenter'

/**
 * Help & Support (docs/backoffice/stage-13-help-support.md), opened from the
 * top bar's Support button: help articles, FAQ, support tickets (13b) and
 * NewTech's contacts, each a tab. Needs help-support.view (the API refuses otherwise).
 */
const { t } = useI18n()
const { data, loading, error, forbidden, load } = useHelpCenter()

const tabs = [
  { to: '/admin/help/articles', labelKey: 'help.tabs.articles' },
  { to: '/admin/help/faq', labelKey: 'help.tabs.faq' },
  { to: '/admin/help/tickets', labelKey: 'help.tabs.tickets' },
  { to: '/admin/help/contact', labelKey: 'help.tabs.contact' },
]

onMounted(() => void load(true))
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('help.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('help.subtitle') }}</p>
    </div>

    <BaseAlert v-if="forbidden" variant="warning">{{ t('help.forbidden') }}</BaseAlert>
    <template v-else>
      <RouteTabBar :tabs="tabs" />
      <BaseAlert v-if="error" variant="danger" class="mb-4">{{ t('help.loadFailed') }}</BaseAlert>
      <div v-if="loading && !data" class="flex justify-center py-10"><BaseSpinner /></div>
      <RouterView v-else-if="data" />
    </template>
  </div>
</template>

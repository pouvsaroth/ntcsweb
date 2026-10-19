<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { websiteApi, type WebsiteSchool } from '../../api'

/**
 * Website (stage 7): NewTech's public website, edited here instead of in the
 * school app. Each tab is its own route.
 */
const { t } = useI18n()

const tabs = ['services', 'slides', 'about', 'gallery', 'downloads', 'promotions', 'news', 'events', 'announcements', 'documents'] as const

const school = ref<WebsiteSchool | null>(null)
const schoolError = ref<string | null>(null)

onMounted(async () => {
  try {
    school.value = await websiteApi.school()
  } catch (e) {
    schoolError.value = e instanceof Error ? e.message : t('bo.common.failed')
  }
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('bo.website.title') }}</h1>
      <a
        v-if="school"
        :href="`https://${school.hostname}`"
        target="_blank"
        rel="noopener"
        class="text-sm text-secondary-700 hover:underline"
      >
        {{ school.hostname }} ↗
      </a>
    </div>
    <p v-if="schoolError" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ schoolError }}</p>

    <nav class="mb-6 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-neutral-200">
      <RouterLink
        v-for="tab in tabs"
        :key="tab"
        :to="`/website/${tab}`"
        class="-mb-px border-b-2 border-transparent px-3 py-2 text-sm font-medium whitespace-nowrap text-neutral-500 hover:text-neutral-800"
        active-class="!border-primary-500 !text-neutral-900"
      >
        {{ t(`bo.website.tabs.${tab}`) }}
      </RouterLink>
    </nav>
    <RouterView v-if="!schoolError" />
  </div>
</template>

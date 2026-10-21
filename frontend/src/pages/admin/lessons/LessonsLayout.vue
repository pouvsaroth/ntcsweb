<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { lessonTabs } from '@/router/lessonTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * Academic > Lesson plans (docs/academics/lesson-plans.md) — plans, the week,
 * the daily teaching log, review, curriculum progress and delays, as tabs.
 */
const { t } = useI18n()
const auth = useAuthStore()

const tabs = computed(() => lessonTabs.filter((tab) => tab.permission.some((p) => auth.can(p))))
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('lessonPlans.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('lessonPlans.subtitle') }}</p>
    </div>

    <RouteTabBar :tabs="tabs" />

    <RouterView />
  </div>
</template>

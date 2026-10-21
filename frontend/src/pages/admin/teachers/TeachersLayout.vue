<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import RouteTabBar from '@/components/ui/RouteTabBar.vue'
import { teacherTabs } from '@/router/teacherTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * HRM > Teacher (docs/hrm/teachers.md) — one sidebar link, each section a tab
 * rendered into the <RouterView> below as a child route. Teachers are staff
 * in a position that carries the Teacher role (HRM > Staff).
 */
const { t } = useI18n()
const auth = useAuthStore()

const tabs = computed(() => teacherTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('teacherMgmt.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">
        {{ t('teacherMgmt.subtitle') }}
        <RouterLink to="/admin/staff" class="text-secondary-700 hover:underline">{{ t('teacherMgmt.staffLink') }}</RouterLink>
      </p>
    </div>

    <RouteTabBar :tabs="tabs" />

    <RouterView />
  </div>
</template>

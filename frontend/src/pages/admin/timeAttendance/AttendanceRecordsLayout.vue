<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { attendanceRecordTabs } from '@/router/attendanceTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * HRM > Attendance & Time > Attendance records — Late, Early leave, Absence,
 * Overtime and Attendance correction as sub-tabs (pills, so they read as a
 * level below the main tab bar), each a child route.
 */
const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()

const tabs = computed(() => attendanceRecordTabs.filter((tab) => auth.can(tab.permission)))
</script>

<template>
  <div>
    <nav class="-mt-2 mb-5 flex gap-2 overflow-x-auto [scrollbar-width:none] sm:flex-wrap sm:overflow-visible [&::-webkit-scrollbar]:hidden">
      <RouterLink
        v-for="tab in tabs.filter((tab) => !auth.tabHidden(tab.to))"
        :key="tab.to"
        :to="tab.to"
        class="whitespace-nowrap rounded-full border px-3 py-1.5 text-sm font-medium"
        :class="
          route.path === tab.to || route.path.startsWith(tab.to + '/')
            ? 'border-primary-600 bg-primary-50 text-primary-700'
            : 'border-neutral-200 bg-white text-neutral-600 hover:border-neutral-300 hover:text-neutral-800'
        "
      >
        {{ t(tab.labelKey) }}
      </RouterLink>
    </nav>

    <RouterView />
  </div>
</template>

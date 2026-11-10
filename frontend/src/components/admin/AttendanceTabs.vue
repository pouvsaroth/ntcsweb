<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * Shared tab bar of "Student Attendance" (take/review attendance),
 * "Attendance Summary" (per-student totals) and "Attendance Sheet" (day-by-day
 * marks, exported) — mirrors InvoicesTabs.vue.
 */
const { t } = useI18n()
const route = useRoute()

const auth = useAuthStore()
const tabs = [
  { to: '/admin/attendance', labelKey: 'admin.attendance.title' },
  { to: '/admin/attendance/summary', labelKey: 'admin.attendance.summaryTab' },
  { to: '/admin/attendance/sheet', labelKey: 'admin.attendance.sheetTab', permission: 'attendance.view' },
]
</script>

<template>
  <div class="mb-6 border-b border-neutral-200">
    <nav class="-mb-px flex gap-x-6 overflow-x-auto [scrollbar-width:none] sm:flex-wrap sm:gap-y-1 sm:overflow-visible [&::-webkit-scrollbar]:hidden">
      <RouterLink
        v-for="tab in tabs.filter((tab) => !auth.tabHidden(tab.to) && (!tab.permission || auth.can(tab.permission)))"
        :key="tab.to"
        :to="tab.to"
        class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium"
        :class="
          route.path === tab.to
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700'
        "
      >
        {{ t(tab.labelKey) }}
      </RouterLink>
    </nav>
  </div>
</template>

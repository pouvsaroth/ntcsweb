<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

/**
 * Shared tab bar between "Student Attendance" (take/review attendance) and
 * "Attendance Summary" (per-student totals) — mirrors InvoicesTabs.vue.
 */
const { t } = useI18n()
const route = useRoute()

const tabs = [
  { to: '/admin/attendance', labelKey: 'admin.attendance.title' },
  { to: '/admin/attendance/summary', labelKey: 'admin.attendance.summaryTab' },
]
</script>

<template>
  <div class="mb-6 border-b border-neutral-200">
    <nav class="-mb-px flex flex-wrap gap-x-6 gap-y-1">
      <RouterLink
        v-for="tab in tabs"
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

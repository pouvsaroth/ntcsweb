<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { examinationTabs } from '@/router/examinationTabs'
import { useAuthStore } from '@/stores/auth'

/**
 * The tab bar across the Examination pages (examinationTabs.ts): the
 * gradebook's tabs, then the Computer School's Exams / Grades / Make-up Exam /
 * Certificate. Rendered in the top header (AdminHeader.vue, via the routes'
 * `headerTabs` meta) in place of the section title. Each tab shows only for
 * an account holding one of its permissions.
 */
const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()

const tabs = computed(() => examinationTabs.filter((tab) => tab.permission.some((p) => auth.can(p))))
</script>

<template>
  <!-- h-12 in AdminHeader's own row below the header on phone, h-16 inline in the header from sm up. -->
  <nav class="-mb-px flex h-12 min-w-0 gap-x-6 overflow-x-auto [scrollbar-width:none] sm:h-16 [&::-webkit-scrollbar]:hidden">
    <RouterLink
      v-for="tab in tabs"
      :key="tab.to"
      :to="tab.to"
      class="flex shrink-0 items-center whitespace-nowrap border-b-2 px-1 text-sm font-medium"
      :class="
        route.path === tab.to
          ? 'border-primary-600 text-primary-700'
          : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700'
      "
    >
      {{ t(tab.labelKey) }}
    </RouterLink>
  </nav>
</template>

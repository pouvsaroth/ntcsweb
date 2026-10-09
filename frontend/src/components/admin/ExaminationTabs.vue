<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * Shared tab bar across the "Examination" pages — Exams and Grades used to
 * be separate sidebar entries under Academic Records; they're now reached
 * through one "Examination" item, with this tab bar as the switcher between
 * them. Rendered in the top header (AdminHeader.vue, via the routes'
 * `headerTabs` meta) in place of the section title, rather than in each
 * page body.
 */
const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()

/**
 * Grades needs its own exam-scores.* permission — distinct from
 * exam-applications.view, which only gates Exams/Approvals — so a role
 * can hold one without the other (see ExamScorePolicy/ExamApplicationPolicy).
 * Every other sidebar item hides itself the same way (isNavItemVisible in
 * adminNav.ts); showing a tab unconditionally here used to mean a role
 * without exam-scores.view could click into Grades and get nothing but an
 * error — see Grades.vue's onMounted fix.
 */
const allTabs = computed(() => [
  { to: '/admin/exams', labelKey: 'adminNav.items.exams', visible: auth.can('exam-applications.view') },
  { to: '/admin/grades', labelKey: 'adminNav.items.grades', visible: auth.can('exam-scores.view') || auth.can('exam-scores.manage-all') },
  // Make-up applications are exam applications (STATUS_MAKE_UP, see
  // ExamApplication), so this reuses the same permission as Exams/Approvals
  // rather than the Grades tab's exam-scores.* one.
  { to: '/admin/exams/make-up', labelKey: 'adminNav.items.makeUpExam', visible: auth.can('exam-applications.view') },
  // Passed students (score ≥ 85) and their certificate photo — exam
  // applications again, so the same permission as Exams.
  { to: '/admin/exams/certificate', labelKey: 'adminNav.items.certificate', visible: auth.can('exam-applications.view') },
])

const tabs = computed(() => allTabs.value.filter((tab) => tab.visible))
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

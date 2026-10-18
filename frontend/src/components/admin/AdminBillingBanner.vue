<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/utils/date'

/**
 * The school owner's billing reminders (back office billing, stage 5e): the
 * plan or trial ending soon, ended and about to be suspended, or the plan's
 * student limit nearly or fully used. Only owners and admins ever receive a
 * notice (see backend BillingNotice); everyone else sees nothing.
 */
const { t } = useI18n()
const auth = useAuthStore()

const lines = computed(() => {
  const notice = auth.billingNotice
  if (!notice) return []
  const out: { text: string; urgent: boolean }[] = []

  const plan = notice.plan
  if (plan?.kind === 'ending') {
    const key = plan.days_left === 0 ? (plan.trial ? 'trialEndsToday' : 'endsToday') : plan.trial ? 'trialEnding' : 'ending'
    out.push({ text: t(`billingBanner.${key}`, { date: formatDate(plan.end_on), days: plan.days_left }), urgent: plan.days_left <= 3 })
  } else if (plan?.kind === 'ended') {
    out.push({
      text: t(plan.trial ? 'billingBanner.trialEnded' : 'billingBanner.ended', { date: formatDate(plan.end_on), suspend: formatDate(plan.suspend_on) }),
      urgent: true,
    })
  }

  const students = notice.students
  if (students) {
    out.push({
      text: t(students.full ? 'billingBanner.studentsFull' : 'billingBanner.studentsNear', {
        active: students.active.toLocaleString(),
        limit: students.limit.toLocaleString(),
      }),
      urgent: students.full,
    })
  }

  return out
})
</script>

<template>
  <div v-if="lines.length" class="space-y-px">
    <div
      v-for="(line, i) in lines"
      :key="i"
      role="status"
      class="flex items-start gap-2 px-4 py-2 text-sm sm:px-6"
      :class="line.urgent ? 'bg-red-50 text-red-800' : 'bg-amber-50 text-amber-900'"
    >
      <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"
        />
      </svg>
      <span>{{ line.text }}</span>
    </div>
  </div>
</template>
